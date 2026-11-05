import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'partner_api.dart';

class LiveLocationSharing extends StatefulWidget {
  const LiveLocationSharing({
    super.key,
    required this.api,
    required this.reference,
  });
  final PartnerApi api;
  final String reference;
  @override
  State<LiveLocationSharing> createState() => _LiveLocationSharingState();
}

class _LiveLocationSharingState extends State<LiveLocationSharing>
    with WidgetsBindingObserver {
  static const channel = MethodChannel('dropbell/location');
  bool enabled = false, busy = false, foreground = true;
  String? session, error;
  Timer? timer;
  int generation = 0;
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    generation++;
    timer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    if (session != null) {
      unawaited(
        widget.api
            .request('DELETE', 'orders/${widget.reference}/location')
            .catchError((_) => <String, dynamic>{}),
      );
    }
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (!foreground && enabled) unawaited(stop());
  }

  Future<void> stop() async {
    generation++;
    timer?.cancel();
    if (mounted) setState(() => enabled = false);
    session = null;
    try {
      await widget.api.request('DELETE', 'orders/${widget.reference}/location');
    } catch (_) {
      if (mounted) {
        setState(
          () => error =
              'Sharing stopped on this phone. The last update expires within two minutes.',
        );
      }
    }
  }

  Future<void> update() async {
    if (busy || !enabled || !foreground) return;
    busy = true;
    final current = generation;
    try {
      final point = await channel
          .invokeMapMethod<String, dynamic>('currentLocation')
          .timeout(const Duration(seconds: 25));
      if (!mounted || !enabled || !foreground || current != generation) return;
      if (point == null) {
        throw const ApiFailure('Could not obtain a current location.');
      }
      session ??= (await widget.api.request(
        'POST',
        'orders/${widget.reference}/location/start',
        {},
      ))['sharing_session'];
      if (!mounted || !enabled || !foreground || current != generation) {
        session = null;
        await widget.api.request(
          'DELETE',
          'orders/${widget.reference}/location',
        );
        return;
      }
      await widget.api.request('POST', 'orders/${widget.reference}/location', {
        'sharing_session': session,
        'latitude': point['latitude'],
        'longitude': point['longitude'],
        'accuracy_meters': (point['accuracy_meters'] as num).round(),
      });
      if (mounted) setState(() => error = null);
    } catch (e) {
      if (mounted) {
        setState(
          () => error = e is PlatformException
              ? (e.message ?? 'Enable location access to share your position.')
              : e.toString(),
        );
      }
      if (session == null && mounted) setState(() => enabled = false);
    } finally {
      busy = false;
    }
  }

  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Share delivery location'),
            subtitle: const Text(
              'Shares with this customer while this screen is open. Stops when the app goes into the background.',
            ),
            value: enabled,
            onChanged: busy
                ? null
                : (value) async {
                    if (!value) {
                      await stop();
                      return;
                    }
                    generation++;
                    setState(() => enabled = true);
                    await update();
                    if (enabled) {
                      timer = Timer.periodic(
                        const Duration(seconds: 15),
                        (_) => update(),
                      );
                    }
                  },
          ),
          if (error != null)
            Text(error!, style: const TextStyle(color: Colors.red)),
          if (enabled)
            const Text(
              'Location sharing is on. Updates about every 15 seconds.',
            ),
        ],
      ),
    ),
  );
}
