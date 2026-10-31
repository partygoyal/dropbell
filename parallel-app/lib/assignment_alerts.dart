import 'package:flutter/services.dart';

class AssignmentAlerts {
  AssignmentAlerts({required this.request, this.play});
  final Future<Map<String, dynamic>> Function(String path) request;
  final Future<void> Function()? play;
  static const channel = MethodChannel('dropbell/order_alerts');
  int? cursor;
  final Set<int> heard = {};
  bool busy = false, disposed = false;

  Future<void> accept(Map<String, dynamic> event) async {
    final type = event['event_type'];
    final id = int.tryParse('${event['notification_id'] ?? event['id']}');
    if (disposed ||
        id == null ||
        type is! String ||
        !type.startsWith('assigned:') ||
        !heard.add(id)) {
      return;
    }
    if (heard.length > 1000) heard.remove(heard.first);
    try {
      if (play != null) {
        await play!();
      } else {
        await channel.invokeMethod<void>('playAssignment');
      }
    } catch (_) {
      // A sound failure must never interrupt a delivery refresh.
    }
  }

  Future<void> poll({bool baseline = false}) async {
    if (busy || disposed) return;
    busy = true;
    try {
      final result = await request(
        cursor == null || baseline
            ? 'notifications'
            : 'notifications?after=$cursor',
      );
      if (disposed) return;
      if (cursor == null || baseline) {
        cursor = result['latest_id'] as int?;
      } else {
        for (final event in (result['data'] as List)) {
          await accept(Map<String, dynamic>.from(event));
        }
        cursor = result['cursor'] as int? ?? cursor;
      }
    } catch (_) {
      // Keep the cursor so the next refresh retries missed assignment events.
    } finally {
      busy = false;
    }
  }

  void dispose() {
    disposed = true;
  }
}
