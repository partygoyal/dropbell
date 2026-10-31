import 'package:dropbell_partner/assignment_alerts.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test(
    'existing inbox establishes baseline; polling and push deduplicate assignments',
    () async {
      var played = 0;
      var calls = 0;
      final alerts = AssignmentAlerts(
        play: () async {
          played++;
        },
        request: (path) async {
          calls++;
          if (calls == 1) {
            return {
              'latest_id': 10,
              'data': [
                {'id': 10, 'event_type': 'assigned:1'},
              ],
            };
          }
          expect(path, 'notifications?after=10');
          return {
            'cursor': 12,
            'data': [
              {'id': 11, 'event_type': 'assigned:2'},
              {'id': 12, 'event_type': 'cancelled:3'},
            ],
          };
        },
      );
      await alerts.poll();
      expect(played, 0);
      await alerts.accept({
        'notification_id': '11',
        'event_type': 'assigned:2',
      });
      await alerts.poll();
      expect(played, 1);
      expect(alerts.cursor, 12);
      await alerts.accept({'id': 13, 'event_type': 'assigned:4'});
      expect(played, 2);
      alerts.dispose();
      await alerts.accept({'id': 14, 'event_type': 'assigned:5'});
      expect(played, 2);
    },
  );

  test(
    'failed polls preserve cursor and sound errors do not interrupt updates',
    () async {
      var fail = false;
      final alerts = AssignmentAlerts(
        play: () async {
          throw StateError('muted');
        },
        request: (_) async {
          if (fail) throw StateError('offline');
          return {
            'latest_id': 5,
            'cursor': 6,
            'data': [
              {'id': 6, 'event_type': 'assigned:1'},
            ],
          };
        },
      );
      await alerts.poll();
      fail = true;
      await alerts.poll();
      expect(alerts.cursor, 5);
      fail = false;
      await alerts.poll();
      expect(alerts.cursor, 6);
    },
  );
}
