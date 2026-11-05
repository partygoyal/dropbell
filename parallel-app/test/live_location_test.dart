import 'dart:async';
import 'dart:convert';
import 'package:dropbell_partner/live_location.dart';
import 'package:dropbell_partner/partner_api.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'partner_api_test.dart' as fixtures;

void main() {
  const channel = MethodChannel('dropbell/location');
  testWidgets(
    'sharing is opt-in and stops publishing when the app enters the background',
    (tester) async {
      var fixes = 0, updates = 0, stops = 0;
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(channel, (
        _,
      ) async {
        fixes++;
        return {'latitude': 30.31, 'longitude': 78.03, 'accuracy_meters': 20};
      });
      final api = PartnerApi(
        baseUrl: 'https://example.test/api',
        store: fixtures.MemoryTokens(),
        client: MockClient((request) async {
          if (request.method == 'DELETE') {
            stops++;
            return http.Response('{}', 200);
          }
          if (request.url.path.endsWith('/start')) {
            return http.Response(
              jsonEncode({'sharing_session': 's' * 64}),
              200,
            );
          }
          updates++;
          expect(jsonDecode(request.body)['sharing_session'], 's' * 64);
          return http.Response('{}', 200);
        }),
      );
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: LiveLocationSharing(api: api, reference: 'order-123'),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(fixes, 0);
      expect(updates, 0);
      await tester.tap(find.byType(Switch));
      await tester.pumpAndSettle();
      expect(fixes, 1);
      expect(updates, 1);
      await tester.pump(const Duration(seconds: 15));
      await tester.pumpAndSettle();
      expect(updates, 2);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pumpAndSettle();
      expect(stops, 1);
      expect(tester.widget<Switch>(find.byType(Switch)).value, isFalse);
      await tester.pump(const Duration(seconds: 30));
      expect(updates, 2);
      await tester.pumpWidget(const SizedBox.shrink());
      api.close();
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        channel,
        null,
      );
    },
  );
  testWidgets('a delayed GPS result cannot start sharing after backgrounding', (
    tester,
  ) async {
    final fix = Completer<Map<String, dynamic>>();
    var starts = 0;
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      channel,
      (_) async => fix.future,
    );
    final api = PartnerApi(
      baseUrl: 'https://example.test/api',
      store: fixtures.MemoryTokens(),
      client: MockClient((r) async {
        if (r.method == 'POST') starts++;
        return http.Response('{}', 200);
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: LiveLocationSharing(api: api, reference: 'order-123'),
        ),
      ),
    );
    await tester.tap(find.byType(Switch));
    await tester.pump();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    fix.complete({
      'latitude': 30.31,
      'longitude': 78.03,
      'accuracy_meters': 20,
    });
    await tester.pumpAndSettle();
    expect(starts, 0);
    await tester.pumpWidget(const SizedBox.shrink());
    api.close();
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      channel,
      null,
    );
  });
}
