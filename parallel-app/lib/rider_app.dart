import 'dart:async';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';
import 'partner_api.dart';
import 'package:dropbell_mobile_notifications/notifications.dart';

class RiderEntry extends StatefulWidget {
  const RiderEntry({super.key, required this.demoBuilder, this.api});
  final Widget Function() demoBuilder;
  final PartnerApi? api;
  @override
  State<RiderEntry> createState() => _RiderEntryState();
}

class _RiderEntryState extends State<RiderEntry> {
  late final api = widget.api ?? PartnerApi.configured();
  bool restoring = true;
  String? error;
  @override
  void initState() {
    super.initState();
    restore();
  }

  @override
  void dispose() {
    if (widget.api == null) api.close();
    super.dispose();
  }

  Future<void> restore() async {
    setState(() {
      restoring = true;
      error = null;
    });
    try {
      await api.restore();
    } catch (_) {
      error = 'Could not read your saved session. Please retry.';
    }
    if (mounted) setState(() => restoring = false);
  }

  @override
  Widget build(BuildContext context) {
    if (restoring) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    if (error != null) {
      return Scaffold(
        body: SafeArea(
          child: Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(error!),
                TextButton(onPressed: restore, child: const Text('Retry')),
              ],
            ),
          ),
        ),
      );
    }
    if (api.signedIn) {
      return RiderHome(
        api: api,
        onSignedOut: () {
          if (mounted) setState(() {});
        },
      );
    }
    return RiderLogin(
      api: api,
      onSignedIn: () => setState(() {}),
      onDemo: () => Navigator.of(
        context,
      ).push(MaterialPageRoute<void>(builder: (_) => widget.demoBuilder())),
    );
  }
}

class RiderLogin extends StatefulWidget {
  const RiderLogin({
    super.key,
    required this.api,
    required this.onSignedIn,
    required this.onDemo,
  });
  final PartnerApi api;
  final VoidCallback onSignedIn, onDemo;
  @override
  State<RiderLogin> createState() => _RiderLoginState();
}

class _RiderLoginState extends State<RiderLogin> {
  final phone = TextEditingController();
  final code = TextEditingController();
  final password = TextEditingController();
  bool useCode = false, obscurePassword = true;
  final form = GlobalKey<FormState>();
  String? challenge, localCode, error;
  bool busy = false;
  @override
  void dispose() {
    phone.dispose();
    code.dispose();
    password.dispose();
    super.dispose();
  }

  Future<void> submit() async {
    if (busy || !form.currentState!.validate()) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      if (!useCode) {
        await widget.api.signIn(phone.text.trim(), password.text);
        if (mounted) widget.onSignedIn();
      } else if (challenge == null) {
        final result = await widget.api.requestOtp(phone.text.trim());
        if (mounted) {
          setState(() {
            challenge = result['challenge_id'];
            localCode = result['local_code'];
          });
        }
      } else {
        await widget.api.verifyOtp(challenge!, code.text.trim());
        if (mounted) widget.onSignedIn();
      }
    } catch (e) {
      if (mounted) setState(() => error = partnerError(e));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    body: SafeArea(
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 480),
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(28),
            child: Form(
              key: form,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(
                    Icons.delivery_dining,
                    size: 88,
                    color: Color(0xFF0861FF),
                  ),
                  const SizedBox(height: 20),
                  Text(
                    'dropbell partner',
                    style: Theme.of(context).textTheme.headlineMedium,
                  ),
                  const SizedBox(height: 12),
                  Text(
                    challenge == null
                        ? 'Sign in to your rider account'
                        : 'Verify your mobile number',
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  const SizedBox(height: 10),
                  const Text(
                    'Your admin must activate your rider account and approve KYC before you can sign in.',
                  ),
                  const SizedBox(height: 28),
                  TextFormField(
                    controller: phone,
                    enabled: challenge == null && !busy,
                    keyboardType: TextInputType.phone,
                    autofillHints: const [
                      AutofillHints.telephoneNumberNational,
                    ],
                    inputFormatters: [
                      FilteringTextInputFormatter.digitsOnly,
                      LengthLimitingTextInputFormatter(10),
                    ],
                    decoration: const InputDecoration(
                      labelText: 'Mobile number',
                      prefixText: '+91 ',
                    ),
                    validator: (value) =>
                        RegExp(r'^[6-9]\d{9}$').hasMatch(value?.trim() ?? '')
                        ? null
                        : 'Enter a valid 10-digit mobile number',
                  ),
                  if (!useCode) ...[
                    const SizedBox(height: 18),
                    TextFormField(
                      controller: password,
                      enabled: !busy,
                      obscureText: obscurePassword,
                      autofillHints: const [AutofillHints.password],
                      decoration: InputDecoration(
                        labelText: 'App password',
                        suffixIcon: IconButton(
                          onPressed: () => setState(
                            () => obscurePassword = !obscurePassword,
                          ),
                          tooltip: obscurePassword
                              ? 'Show password'
                              : 'Hide password',
                          icon: Icon(
                            obscurePassword
                                ? Icons.visibility_outlined
                                : Icons.visibility_off_outlined,
                          ),
                        ),
                      ),
                      validator: (value) => value == null || value.isEmpty
                          ? 'Enter the password provided by your admin'
                          : null,
                      onFieldSubmitted: (_) => submit(),
                    ),
                    const SizedBox(height: 12),
                    const Text(
                      'Use the app password issued by your admin. Contact your admin if you forgot it or need access reset.',
                    ),
                  ],
                  if (useCode && challenge != null) ...[
                    const SizedBox(height: 18),
                    TextFormField(
                      controller: code,
                      enabled: !busy,
                      keyboardType: TextInputType.number,
                      autofillHints: const [AutofillHints.oneTimeCode],
                      inputFormatters: [
                        FilteringTextInputFormatter.digitsOnly,
                        LengthLimitingTextInputFormatter(6),
                      ],
                      decoration: const InputDecoration(
                        labelText: '6-digit code',
                      ),
                      validator: (value) =>
                          RegExp(r'^\d{6}$').hasMatch(value ?? '')
                          ? null
                          : 'Enter the 6-digit code',
                    ),
                    if (localCode != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 14),
                        child: SelectableText(
                          'Local development code: $localCode\nExpires in 5 minutes. No SMS was sent.',
                        ),
                      ),
                    TextButton(
                      onPressed: busy
                          ? null
                          : () => setState(() {
                              challenge = null;
                              localCode = null;
                              code.clear();
                              error = null;
                            }),
                      child: const Text('Change number / request a new code'),
                    ),
                  ],
                  if (error != null) ErrorNotice(error!),
                  const SizedBox(height: 22),
                  FilledButton(
                    onPressed: busy ? null : submit,
                    child: Text(
                      busy
                          ? 'Please wait…'
                          : !useCode
                          ? 'Sign in'
                          : challenge == null
                          ? 'Get sign-in code'
                          : 'Verify & sign in',
                    ),
                  ),
                  if (!kReleaseMode) ...[
                    const SizedBox(height: 12),
                    TextButton(
                      onPressed: busy
                          ? null
                          : () => setState(() {
                              useCode = !useCode;
                              challenge = null;
                              localCode = null;
                              code.clear();
                              error = null;
                            }),
                      child: Text(
                        useCode ? 'Use app password' : 'Use a development code',
                      ),
                    ),
                    const SizedBox(height: 12),
                    OutlinedButton(
                      onPressed: busy ? null : widget.onDemo,
                      child: const Text('Explore sample deliveries'),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    ),
  );
}

class RiderHome extends StatefulWidget {
  const RiderHome({super.key, required this.api, required this.onSignedOut});
  final PartnerApi api;
  final VoidCallback onSignedOut;
  @override
  State<RiderHome> createState() => _RiderHomeState();
}

class _RiderHomeState extends State<RiderHome> with WidgetsBindingObserver {
  late final notifications = MobileNotifications(
    audience: 'partner',
    request: widget.api.request,
    readStorage: (key) =>
        SecureTokenStore.storage.read(key: '${widget.api.baseUrl}:$key'),
    writeStorage: (key, value) => SecureTokenStore.storage.write(
      key: '${widget.api.baseUrl}:$key',
      value: value,
    ),
    signedIn: () => widget.api.signedIn,
    openOrder: (reference) async {
      if (!mounted || !widget.api.signedIn) return;
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => RiderDelivery(api: widget.api, reference: reference),
        ),
      );
      if (mounted) {
        if (!widget.api.signedIn) {
          widget.onSignedOut();
        } else {
          await refresh();
        }
      }
    },
    foreground: (message) {
      if (mounted && widget.api.signedIn) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(message)));
      }
    },
  );
  Future<void> inbox() async {
    try {
      await notifications.resume();
    } catch (_) {}
    if (!mounted) return;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => NotificationInbox(notifications: notifications),
      ),
    );
    if (mounted) {
      if (!widget.api.signedIn) {
        widget.onSignedOut();
      } else {
        await refresh();
      }
    }
  }

  RiderProfile? profile;
  List<RiderOrder> orders = [];
  int tab = 0;
  int? nextPage;
  bool busy = false;
  bool foreground = true;
  Timer? refreshTimer;
  DateTime? refreshedAt;
  String? error;
  String get scope => tab == 1 ? 'history' : 'active';
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    unawaited(notifications.resume().catchError((_) {}));
    refresh();
    refreshTimer = Timer.periodic(const Duration(seconds: 30), (_) {
      if (mounted &&
          foreground &&
          !busy &&
          tab == 0 &&
          (ModalRoute.of(context)?.isCurrent ?? false)) {
        refresh();
      }
    });
  }

  @override
  void dispose() {
    refreshTimer?.cancel();
    unawaited(notifications.dispose());
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (foreground && !busy && (ModalRoute.of(context)?.isCurrent ?? false)) {
      refresh();
    }
  }

  void failed(Object e) {
    if (e is ApiFailure && e.status == 401) {
      widget.onSignedOut();
      return;
    }
    if (mounted) setState(() => error = partnerError(e));
  }

  Future<void> refresh() async {
    if (busy) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final results = await Future.wait<Object>([
        widget.api.profile(),
        widget.api.orders(1, scope),
      ]);
      final rider = results[0] as RiderProfile;
      final page = results[1] as OrderPage;
      if (mounted) {
        setState(() {
          profile = rider;
          orders = page.orders;
          nextPage = page.nextPage;
          refreshedAt = DateTime.now();
        });
      }
    } catch (e) {
      failed(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> more() async {
    if (busy || nextPage == null) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final page = await widget.api.orders(nextPage!, scope);
      if (mounted) {
        setState(() {
          final merged = {
            for (final order in orders) order.reference: order,
            for (final order in page.orders) order.reference: order,
          };
          orders = merged.values.toList();
          nextPage = page.nextPage;
        });
      }
    } catch (e) {
      failed(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> availability(bool value) async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final rider = await widget.api.availability(value);
      if (mounted) setState(() => profile = rider);
    } catch (e) {
      failed(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> open(RiderOrder order) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) =>
            RiderDelivery(api: widget.api, reference: order.reference),
      ),
    );
    if (mounted) {
      if (!widget.api.signedIn) {
        widget.onSignedOut();
      } else {
        await refresh();
      }
    }
  }

  Future<void> logout() async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.api.logout();
      if (mounted) widget.onSignedOut();
    } catch (e) {
      failed(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final active = orders.where((o) => o.active).toList();
    final history = orders.where((o) => !o.active).toList();
    return Scaffold(
      appBar: AppBar(
        title: const Text('dropbell partner'),
        actions: [
          IconButton(
            onPressed: inbox,
            tooltip: 'Notifications',
            icon: const Icon(Icons.notifications_outlined),
          ),
          IconButton(
            onPressed: busy ? null : refresh,
            tooltip: 'Refresh',
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: tab,
        onDestinationSelected: busy
            ? null
            : (value) {
                if (tab == value) return;
                setState(() {
                  tab = value;
                  orders = [];
                  nextPage = null;
                });
                refresh();
              },
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.assignment_outlined),
            label: 'Assigned',
          ),
          NavigationDestination(icon: Icon(Icons.history), label: 'History'),
          NavigationDestination(
            icon: Icon(Icons.person_outline),
            label: 'Account',
          ),
        ],
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
                  if (busy) const LinearProgressIndicator(),
                  if (error != null)
                    ErrorNotice(error!, onRetry: busy ? null : refresh),
                  if (profile != null && tab == 0) ...[
                    Text(
                      'Hello, ${profile!.name}',
                      style: Theme.of(context).textTheme.headlineMedium,
                    ),
                    const SizedBox(height: 8),
                    const Text('Every drop, delivered with care.'),
                    if (refreshedAt != null)
                      Text(
                        'Updated ${TimeOfDay.fromDateTime(refreshedAt!).format(context)} · Refreshes every 30 seconds while this screen is open',
                      ),
                    if (profile!.locations.isEmpty)
                      const ErrorNotice(
                        'No delivery areas are assigned to your account. Contact your admin to finish setup.',
                      ),
                    const SizedBox(height: 20),
                    Surface(
                      child: SwitchListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(
                          profile!.online ? 'You’re online' : 'You’re offline',
                        ),
                        subtitle: const Text(
                          'Availability saved to your rider account',
                        ),
                        value: profile!.online,
                        onChanged: busy ? null : availability,
                      ),
                    ),
                    const SizedBox(height: 18),
                    Text(
                      'Assigned deliveries',
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                    if (active.isEmpty && !busy && error == null)
                      const Surface(
                        child: Text(
                          'No assigned deliveries right now. Pull down to refresh.',
                        ),
                      ),
                    for (final order in active) orderCard(order),
                  ],
                  if (tab == 1) ...[
                    Text(
                      'Delivery history',
                      style: Theme.of(context).textTheme.headlineMedium,
                    ),
                    if (history.isEmpty && !busy && error == null)
                      const Surface(
                        child: Text(
                          'No completed or cancelled deliveries on this page.',
                        ),
                      ),
                    for (final order in history) orderCard(order),
                  ],
                  if (profile != null && tab == 2) ...[
                    Text(
                      'Your account',
                      style: Theme.of(context).textTheme.headlineMedium,
                    ),
                    const SizedBox(height: 18),
                    Surface(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const CircleAvatar(
                            radius: 30,
                            child: Icon(Icons.person, size: 32),
                          ),
                          const SizedBox(height: 16),
                          Text(
                            profile!.name,
                            style: Theme.of(context).textTheme.titleLarge,
                          ),
                          Text('+91 ${profile!.phone}'),
                          const SizedBox(height: 16),
                          Text(profile!.vehicle),
                          const SizedBox(height: 16),
                          const Text(
                            'Assigned service areas',
                            style: TextStyle(fontWeight: FontWeight.bold),
                          ),
                          if (profile!.locations.isEmpty)
                            const Text(
                              'No delivery areas assigned. Contact your admin.',
                            ),
                          for (final location in profile!.locations)
                            Padding(
                              padding: const EdgeInsets.only(top: 8),
                              child: Text(location),
                            ),
                          const SizedBox(height: 16),
                          const SelectableText('Support: settings@dropbell.in'),
                          const Text(
                            'For account access, delivery problems or cash collection disputes, contact your admin. Never collect payment twice.',
                          ),
                          const SizedBox(height: 12),
                          const Chip(
                            avatar: Icon(
                              Icons.verified_user,
                              color: Colors.green,
                            ),
                            label: Text('KYC approved'),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 18),
                    FilledButton(
                      onPressed: busy ? null : logout,
                      child: const Text('Sign out'),
                    ),
                  ],
                  if (tab != 2 && nextPage != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 16),
                      child: OutlinedButton(
                        onPressed: busy ? null : more,
                        child: const Text('Load more deliveries'),
                      ),
                    ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget orderCard(RiderOrder order) => Padding(
    padding: const EdgeInsets.only(top: 14),
    child: Surface(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            order.reference,
            style: const TextStyle(fontWeight: FontWeight.bold),
          ),
          const SizedBox(height: 8),
          Chip(label: Text(order.label)),
          Text('${order.stops.length} pickup stops • ${order.address['name']}'),
          const SizedBox(height: 8),
          Text(formatAddress(order.address)),
          const SizedBox(height: 14),
          if (order.active)
            Text(
              order.codPaise > 0
                  ? 'Collect ${money(order.codPaise)} on delivery'
                  : 'No payment to collect',
            ),
          if (order.receipt != null)
            Text('Collection receipt: ${order.receipt}'),
          if (order.deliveredAt != null)
            Text('Delivered: ${order.deliveredAt!.toLocal()}'),
          const SizedBox(height: 12),
          FilledButton(
            onPressed: busy ? null : () => open(order),
            child: Text(order.active ? 'View delivery →' : 'View details'),
          ),
        ],
      ),
    ),
  );
}

class RiderDelivery extends StatefulWidget {
  const RiderDelivery({super.key, required this.api, required this.reference});
  final PartnerApi api;
  final String reference;
  @override
  State<RiderDelivery> createState() => _RiderDeliveryState();
}

class _RiderDeliveryState extends State<RiderDelivery>
    with WidgetsBindingObserver {
  RiderOrder? order;
  String? error;
  bool busy = false, confirming = false, reviewed = false, handedOver = false;
  bool foreground = true, refreshAfterBusy = false;
  int reviewEpoch = 0;
  DateTime? refreshedAt;
  final receipt = TextEditingController();
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    refresh();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    receipt.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    reviewEpoch++;
    if (mounted) {
      setState(() {
        reviewed = false;
        handedOver = false;
      });
    }
    if (foreground) {
      if (busy) {
        refreshAfterBusy = true;
      } else {
        unawaited(refresh());
      }
    }
  }

  void finishBusy() {
    if (!mounted) return;
    setState(() => busy = false);
    if (refreshAfterBusy && foreground && widget.api.signedIn) {
      refreshAfterBusy = false;
      unawaited(refresh());
    }
  }

  Future<void> refresh() async {
    if (busy || confirming) {
      refreshAfterBusy = true;
      return;
    }
    setState(() {
      busy = true;
      reviewed = false;
      error = null;
    });
    final epoch = reviewEpoch;
    try {
      final fresh = await widget.api.order(widget.reference);
      if (mounted) {
        setState(() {
          order = fresh;
          reviewed = foreground && epoch == reviewEpoch;
          refreshedAt = DateTime.now();
          handedOver = false;
          receipt.clear();
        });
      }
    } catch (e) {
      fail(e);
    } finally {
      finishBusy();
    }
  }

  void fail(Object e) {
    if (!mounted) return;
    if (e is ApiFailure && e.status == 401) {
      Navigator.pop(context);
      return;
    }
    setState(() {
      if (e is ApiFailure && e.status == 404) {
        order = null;
        error =
            'This delivery is no longer assigned to you or is no longer available. Return to your assigned deliveries and refresh.';
      } else {
        error = partnerError(e);
      }
      reviewed = false;
    });
  }

  Future<void> update({RiderStop? stop}) async {
    if (busy || confirming || !reviewed || order == null || !foreground) return;
    final current = order!;
    if (stop == null && !handedOver) return;
    if (stop == null && current.codPaise > 0 && receipt.text.trim().isEmpty) {
      setState(() => error = 'Enter the collection receipt reference.');
      return;
    }
    final epoch = reviewEpoch;
    setState(() {
      confirming = true;
      error = null;
    });
    try {
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: Text(stop != null ? 'Confirm pickup?' : 'Confirm delivery?'),
          content: Text(
            stop != null
                ? 'Have you collected every listed item from ${stop.name}?'
                : current.codPaise > 0
                ? 'Confirm you handed over all items and collected ${money(current.codPaise)}.'
                : 'Confirm you handed over all items. No payment should be collected.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Check again'),
            ),
            TextButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Confirm'),
            ),
          ],
        ),
      );
      if (confirmed != true || !mounted) return;
      if (epoch != reviewEpoch || !foreground) {
        setState(() {
          reviewed = false;
          error =
              'The app was interrupted. Refresh and review this delivery before confirming.';
        });
        return;
      }
      setState(() => busy = true);
      final fresh = stop != null
          ? await widget.api.pickup(current, stop)
          : await widget.api.complete(
              current,
              collected: handedOver && current.codPaise > 0,
              receipt: receipt.text.trim(),
            );
      if (mounted) {
        setState(() {
          order = fresh;
          reviewed = foreground && epoch == reviewEpoch;
          refreshedAt = DateTime.now();
          handedOver = false;
          receipt.clear();
        });
      }
    } catch (e) {
      fail(e);
    } finally {
      if (mounted) setState(() => confirming = false);
      finishBusy();
    }
  }

  Future<void> launch(Uri uri) async {
    try {
      if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
        throw const ApiFailure('No app is available to open this action.');
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Could not open this action. You can copy the address or phone number instead.',
            ),
          ),
        );
      }
    }
  }

  Widget contact(String address, String? phone, {String? destination}) =>
      Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (address.isNotEmpty) SelectableText(address),
          if (phone != null && phone.isNotEmpty) SelectableText(phone),
          Wrap(
            spacing: 12,
            children: [
              if (address.isNotEmpty)
                OutlinedButton.icon(
                  onPressed: () => launch(
                    Uri.https('www.google.com', '/maps/dir/', {
                      'api': '1',
                      'destination': destination ?? address,
                    }),
                  ),
                  icon: const Icon(Icons.directions),
                  label: const Text('Directions'),
                ),
              if (phone != null && RegExp(r'^[+\d\s-]{8,20}$').hasMatch(phone))
                OutlinedButton.icon(
                  onPressed: () => launch(Uri(scheme: 'tel', path: phone)),
                  icon: const Icon(Icons.call),
                  label: const Text('Call'),
                ),
            ],
          ),
        ],
      );
  @override
  Widget build(BuildContext context) {
    final current = order;
    final stop = current?.nextStop;
    return PopScope(
      canPop: !busy && !confirming,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Delivery details'),
          actions: [
            IconButton(
              onPressed: busy || confirming ? null : refresh,
              tooltip: 'Refresh order',
              icon: const Icon(Icons.refresh),
            ),
          ],
        ),
        body: SafeArea(
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 640),
              child: ListView(
                padding: const EdgeInsets.all(20),
                children: [
                  if (busy) const LinearProgressIndicator(),
                  if (error != null)
                    ErrorNotice(error!, onRetry: busy ? null : refresh),
                  if (current != null) ...[
                    SelectableText(current.reference),
                    if (refreshedAt != null)
                      Text(
                        'Last checked ${TimeOfDay.fromDateTime(refreshedAt!).format(context)}',
                      ),
                    if (!reviewed && !busy)
                      const ErrorNotice(
                        'Refresh and review this delivery before making changes.',
                      ),
                    const SizedBox(height: 12),
                    Text(
                      current.label.toUpperCase(),
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                    const SizedBox(height: 12),
                    Wrap(
                      spacing: 8,
                      children: [
                        for (var i = 0; i < current.stops.length; i++)
                          Chip(
                            avatar: Icon(
                              current.stops[i].pickedUp
                                  ? Icons.check_circle
                                  : Icons.storefront,
                              size: 18,
                            ),
                            label: Text('Pickup ${i + 1}'),
                          ),
                      ],
                    ),
                    if (current.active && stop != null) ...[
                      const SizedBox(height: 18),
                      Text(
                        'Current pickup',
                        style: Theme.of(context).textTheme.headlineMedium,
                      ),
                      Surface(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              stop.name,
                              style: Theme.of(context).textTheme.titleLarge,
                            ),
                            const SizedBox(height: 12),
                            if (stop.address.isEmpty)
                              const Text(
                                'Pickup address not provided. Contact your admin before collecting.',
                              ),
                            contact(stop.address, stop.phone),
                            const Divider(),
                            for (final item in stop.items)
                              Padding(
                                padding: const EdgeInsets.symmetric(
                                  vertical: 10,
                                ),
                                child: Text(item),
                              ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 18),
                      FilledButton(
                        onPressed: busy || confirming || !reviewed
                            ? null
                            : () => update(stop: stop),
                        child: const Text('Confirm pickup'),
                      ),
                    ] else ...[
                      Surface(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              current.address['name'] ?? 'Customer',
                              style: Theme.of(context).textTheme.titleLarge,
                            ),
                            const SizedBox(height: 12),
                            contact(
                              formatAddress(current.address),
                              current.address['phone'] as String?,
                              destination:
                                  current.address['latitude'] != null &&
                                      current.address['longitude'] != null
                                  ? '${current.address['latitude']},${current.address['longitude']}'
                                  : null,
                            ),
                            const Divider(),
                            for (final pickup in current.stops)
                              for (final item in pickup.items)
                                Padding(
                                  padding: const EdgeInsets.symmetric(
                                    vertical: 8,
                                  ),
                                  child: Text(item),
                                ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 18),
                      if (current.active) ...[
                        Text(
                          current.codPaise > 0
                              ? 'Collect ${money(current.codPaise)}'
                              : 'No payment to collect',
                          style: Theme.of(context).textTheme.headlineMedium,
                        ),
                        const SizedBox(height: 18),
                        if (current.codPaise > 0)
                          TextField(
                            controller: receipt,
                            enabled: !busy && !confirming && reviewed,
                            maxLength: 80,
                            decoration: const InputDecoration(
                              labelText: 'Collection receipt reference',
                            ),
                          ),
                        CheckboxListTile(
                          contentPadding: EdgeInsets.zero,
                          value: handedOver,
                          onChanged: busy || confirming || !reviewed
                              ? null
                              : (value) =>
                                    setState(() => handedOver = value ?? false),
                          title: Text(
                            current.codPaise > 0
                                ? 'Payment collected and all items handed over'
                                : 'All items handed over to the customer',
                          ),
                        ),
                        FilledButton(
                          onPressed:
                              busy || confirming || !reviewed || !handedOver
                              ? null
                              : () => update(),
                          child: const Text('Confirm delivery'),
                        ),
                      ] else ...[
                        Text('Order total: ${money(current.totalPaise)}'),
                        if (current.receipt != null)
                          SelectableText(
                            'Collection receipt: ${current.receipt}',
                          ),
                        if (current.deliveredAt != null)
                          Text('Delivered: ${current.deliveredAt!.toLocal()}'),
                      ],
                    ],
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class Surface extends StatelessWidget {
  const Surface({super.key, required this.child});
  final Widget child;
  @override
  Widget build(BuildContext context) => Card(
    margin: const EdgeInsets.symmetric(vertical: 8),
    color: Colors.white,
    elevation: 0,
    shape: RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(18),
      side: const BorderSide(color: Color(0xFFE1E8F5)),
    ),
    child: Padding(padding: const EdgeInsets.all(18), child: child),
  );
}

class ErrorNotice extends StatelessWidget {
  const ErrorNotice(this.message, {super.key, this.onRetry});
  final String message;
  final VoidCallback? onRetry;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 14),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          message,
          style: TextStyle(color: Theme.of(context).colorScheme.error),
        ),
        if (onRetry != null)
          TextButton(
            onPressed: onRetry,
            child: const Text('Refresh and review'),
          ),
      ],
    ),
  );
}
