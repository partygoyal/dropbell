import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

/// Rebuilds the application after a render failure; saved sessions and pending
/// checkout attempts remain in persistent storage.
class RecoverableApp extends StatefulWidget {
  const RecoverableApp({super.key, required this.builder});
  final Widget Function() builder;

  @override
  State<RecoverableApp> createState() => _RecoverableAppState();
}

class _RecoverableAppState extends State<RecoverableApp> {
  int generation = 0;
  late final ErrorWidgetBuilder previous;
  late final ErrorWidgetBuilder fallback;

  @override
  void initState() {
    super.initState();
    previous = ErrorWidget.builder;
    fallback = (_) => AppRecoveryScreen(onRestart: restart);
    if (kReleaseMode) ErrorWidget.builder = fallback;
  }

  void restart() {
    if (mounted) setState(() => generation++);
  }

  @override
  void dispose() {
    if (ErrorWidget.builder == fallback) ErrorWidget.builder = previous;
    super.dispose();
  }

  @override
  Widget build(BuildContext context) =>
      KeyedSubtree(key: ValueKey(generation), child: widget.builder());
}

class AppRecoveryScreen extends StatelessWidget {
  const AppRecoveryScreen({super.key, required this.onRestart});
  final VoidCallback onRestart;

  @override
  Widget build(BuildContext context) => Directionality(
    textDirection: TextDirection.ltr,
    child: Material(
      color: const Color(0xFFF7FAFC),
      child: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.refresh, size: 40, color: Color(0xFF0861FF)),
              const SizedBox(height: 16),
              const Text(
                'Let’s get Dropbell running again.',
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 12),
              const Text(
                'Reopen the app to restore your saved session. Check your orders or delivery status before repeating an update.',
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 20),
              FilledButton(
                onPressed: onRestart,
                child: const Text('Reopen app'),
              ),
              const SizedBox(height: 12),
              const Text(
                'If this keeps happening, close and reopen the app or contact settings@dropbell.in.',
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      ),
    ),
  );
}
