import 'dart:async';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:uuid/uuid.dart';

typedef NotificationRequest =
    Future<Map<String, dynamic>> Function(
      String method,
      String path, [
      Map<String, dynamic>? body,
    ]);

FirebaseOptions? notificationFirebaseOptions() {
  const key = String.fromEnvironment('FIREBASE_API_KEY');
  const app = String.fromEnvironment('FIREBASE_APP_ID');
  const sender = String.fromEnvironment('FIREBASE_MESSAGING_SENDER_ID');
  const project = String.fromEnvironment('FIREBASE_PROJECT_ID');
  const bundle = String.fromEnvironment('FIREBASE_IOS_BUNDLE_ID');
  if (key.isEmpty || app.isEmpty || sender.isEmpty || project.isEmpty)
    return null;
  return FirebaseOptions(
    apiKey: key,
    appId: app,
    messagingSenderId: sender,
    projectId: project,
    iosBundleId: bundle.isEmpty ? null : bundle,
  );
}

@pragma('vm:entry-point')
Future<void> dropbellBackgroundMessage(RemoteMessage message) async {
  final options = notificationFirebaseOptions();
  if (options != null && Firebase.apps.isEmpty)
    await Firebase.initializeApp(options: options);
}

class MobileNotifications {
  MobileNotifications({
    required this.audience,
    required this.request,
    required this.readStorage,
    required this.writeStorage,
    required this.signedIn,
    required this.openOrder,
    required this.foreground,
  });
  final String audience;
  final NotificationRequest request;
  final Future<String?> Function(String key) readStorage;
  final Future<void> Function(String key, String value) writeStorage;
  final bool Function() signedIn;
  final Future<void> Function(String reference) openOrder;
  final void Function(String message) foreground;
  final List<StreamSubscription<dynamic>> subscriptions = [];
  bool ready = false;
  bool disposed = false;
  String? installation;
  String get storageKey => 'dropbell:$audience:notification-installation';
  String get status => ready
      ? 'Enable push alerts for order updates when the app is closed.'
      : 'Push alerts are not connected yet. Your updates are available in this inbox.';

  Future<void> initialize() async {
    if (disposed ||
        ready ||
        kIsWeb ||
        ![
          TargetPlatform.android,
          TargetPlatform.iOS,
        ].contains(defaultTargetPlatform))
      return;
    final options = notificationFirebaseOptions();
    if (options == null) return;
    try {
      if (Firebase.apps.isEmpty) await Firebase.initializeApp(options: options);
      if (disposed) return;
      FirebaseMessaging.onBackgroundMessage(dropbellBackgroundMessage);
      final messaging = FirebaseMessaging.instance;
      await messaging.setForegroundNotificationPresentationOptions(
        alert: false,
        badge: true,
        sound: true,
      );
      if (disposed) return;
      subscriptions.add(
        FirebaseMessaging.onMessage.listen((message) {
          if (signedIn() && message.data['audience'] == audience && !disposed)
            foreground(
              message.notification?.title ?? 'You have an order update.',
            );
        }),
      );
      subscriptions.add(
        FirebaseMessaging.onMessageOpenedApp.listen((message) {
          unawaited(handleTap(message).catchError((_) {}));
        }),
      );
      // HTTP v1 direct sends use registration tokens; retain this supported token API until the backend adopts FID targets.
      subscriptions.add(
        // ignore: deprecated_member_use
        messaging.onTokenRefresh.listen((token) {
          if (signedIn() && !disposed)
            unawaited(register(token).catchError((_) {}));
        }),
      );
      ready = true;
      final initial = await messaging.getInitialMessage();
      if (initial != null) await handleTap(initial);
    } catch (_) {
      ready = false;
    }
  }

  Future<void> handleTap(RemoteMessage message) async {
    final reference = message.data['order_reference'];
    if (!disposed &&
        signedIn() &&
        message.data['audience'] == audience &&
        reference is String &&
        reference.isNotEmpty) {
      await openOrder(reference);
    }
  }

  Future<void> register(String token) async {
    if (!signedIn() || disposed) return;
    installation ??= await readStorage(storageKey);
    installation ??= const Uuid().v4();
    await writeStorage(storageKey, installation!);
    if (!signedIn() || disposed) return;
    await request('POST', 'notification-devices', {
      'installation_id': installation,
      'token': token,
      'platform': defaultTargetPlatform == TargetPlatform.iOS
          ? 'ios'
          : 'android',
    });
  }

  Future<void> enable() async {
    await initialize();
    if (!ready) throw StateError('Push alerts are not connected yet.');
    final permission = await FirebaseMessaging.instance.requestPermission(
      alert: true,
      badge: true,
      sound: true,
    );
    if (![
      AuthorizationStatus.authorized,
      AuthorizationStatus.provisional,
    ].contains(permission.authorizationStatus))
      throw StateError(
        'Notifications are disabled. You can enable them in your phone settings.',
      );
    if (defaultTargetPlatform == TargetPlatform.iOS &&
        await FirebaseMessaging.instance.getAPNSToken() == null)
      throw StateError(
        'Apple push registration is not ready. Please try again shortly.',
      );
    // ignore: deprecated_member_use
    final token = await FirebaseMessaging.instance.getToken();
    if (token == null)
      throw StateError(
        'Could not register this phone for push alerts. Please retry.',
      );
    await register(token);
  }

  Future<void> resume() async {
    // Push registration is optional; failure must not stop shopping or deliveries.
    try {
      await initialize();
      if (!ready || !signedIn()) return;
      final permission = await FirebaseMessaging.instance
          .getNotificationSettings();
      if ([
        AuthorizationStatus.authorized,
        AuthorizationStatus.provisional,
      ].contains(permission.authorizationStatus)) {
        await enable();
      }
    } catch (_) {}
  }

  Future<void> dispose() async {
    disposed = true;
    for (final subscription in subscriptions) {
      await subscription.cancel();
    }
    subscriptions.clear();
  }
}

class NotificationInbox extends StatefulWidget {
  const NotificationInbox({super.key, required this.notifications});
  final MobileNotifications notifications;
  @override
  State<NotificationInbox> createState() => _NotificationInboxState();
}

class _NotificationInboxState extends State<NotificationInbox> {
  List<Map<String, dynamic>> rows = [];
  int? nextPage;
  int unread = 0;
  bool busy = false;
  String? error, permissionMessage;
  @override
  void initState() {
    super.initState();
    refresh();
  }

  Future<void> refresh([int page = 1]) async {
    if (busy) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final result = await widget.notifications.request(
        'GET',
        'notifications?page=$page',
      );
      if (mounted)
        setState(() {
          final fresh = (result['data'] as List)
              .map((e) => Map<String, dynamic>.from(e))
              .toList();
          rows = page == 1
              ? fresh
              : {
                  for (final row in [...rows, ...fresh]) row['id']: row,
                }.values.toList();
          nextPage = result['next_page'];
          unread = result['unread_count'];
        });
    } catch (_) {
      if (mounted)
        setState(
          () => error =
              'Could not load updates. Check your connection or sign in again.',
        );
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> open(Map<String, dynamic> row) async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.notifications.request(
        'PATCH',
        'notifications/${row['id']}/read',
      );
      final reference = row['order_reference'];
      if (reference is String && reference.isNotEmpty)
        await widget.notifications.openOrder(reference);
    } catch (_) {
      if (mounted)
        setState(
          () => error =
              'This update could not be opened. Refresh or sign in again.',
        );
    } finally {
      if (mounted) setState(() => busy = false);
    }
    if (mounted) await refresh();
  }

  Future<void> enable() async {
    setState(() {
      busy = true;
      permissionMessage = null;
    });
    try {
      await widget.notifications.enable();
      permissionMessage = 'Push alerts enabled for this phone.';
    } catch (e) {
      permissionMessage = e is StateError
          ? e.message.toString()
          : 'Could not enable push alerts. Please try again.';
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text('Notifications${unread > 0 ? ' ($unread)' : ''}'),
    ),
    body: SafeArea(
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 640),
          child: RefreshIndicator(
            onRefresh: refresh,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(20),
              children: [
                Text(widget.notifications.status),
                const SizedBox(height: 12),
                if (widget.notifications.ready)
                  OutlinedButton(
                    onPressed: busy ? null : enable,
                    child: const Text('Enable push alerts'),
                  ),
                if (permissionMessage != null) Text(permissionMessage!),
                if (busy) const LinearProgressIndicator(),
                if (error != null)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    child: Text(
                      error!,
                      style: TextStyle(
                        color: Theme.of(context).colorScheme.error,
                      ),
                    ),
                  ),
                if (!busy && error == null && rows.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 32),
                    child: Text(
                      'You’re all caught up. Your order updates will appear here.',
                    ),
                  ),
                for (final row in rows)
                  Card(
                    child: ListTile(
                      onTap: busy ? null : () => open(row),
                      leading: Icon(
                        row['read'] == true
                            ? Icons.notifications_none
                            : Icons.notifications_active,
                      ),
                      title: Text(
                        row['title'],
                        style: TextStyle(
                          fontWeight: row['read'] == true
                              ? FontWeight.normal
                              : FontWeight.bold,
                        ),
                      ),
                      subtitle: Text(row['body']),
                      trailing: const Icon(Icons.chevron_right),
                    ),
                  ),
                if (nextPage != null)
                  TextButton(
                    onPressed: busy ? null : () => refresh(nextPage!),
                    child: const Text('Load more updates'),
                  ),
                if (!busy)
                  TextButton(onPressed: refresh, child: const Text('Refresh')),
              ],
            ),
          ),
        ),
      ),
    ),
  );
}
