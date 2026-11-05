import 'dart:async';
import 'package:dropbell_partner/app_recovery.dart';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:dropbell_partner/partner_api.dart';
import 'package:dropbell_partner/rider_app.dart';

class MemoryTokens implements TokenStore {
  String? token;
  @override
  Future<String?> read() async => token;
  @override
  Future<void> write(String value) async {
    token = value;
  }

  @override
  Future<void> clear() async {
    token = null;
  }
}

Map<String, dynamic> sampleOrder({int revision = 0, bool picked = false}) => {
  'reference': 'order-123',
  'revision': revision,
  'status': picked ? 'out_for_delivery' : 'received',
  'address': {
    'name': 'Priya',
    'phone': '9876543210',
    'street': '12 Garden Road',
    'city': 'Dehradun',
    'pin': '248001',
  },
  'cod_paise': 12550,
  'total_paise': 12550,
  'receipt': null,
  'delivered_at': null,
  'stops': [
    {
      'id': 'hub',
      'name': 'Daily Dairy',
      'address': {'street': 'Market Road', 'city': 'Dehradun', 'pin': '248001'},
      'phone': null,
      'picked_up': picked,
      'items': [
        {'id': 1, 'name': 'Milk', 'pack_size': '1 L', 'quantity': 2},
      ],
    },
  ],
};

http.Response jsonResponse(Object data, [int status = 200]) => http.Response(
  jsonEncode(data),
  status,
  headers: {'content-type': 'application/json'},
);

void main() {
  test(
    'timeout includes waiting for response headers and does not replay writes',
    () async {
      var calls = 0;
      final api = PartnerApi(
        baseUrl: 'https://example.test/api',
        store: MemoryTokens(),
        timeout: const Duration(milliseconds: 5),
        client: MockClient((_) {
          calls++;
          return Completer<http.Response>().future;
        }),
      );
      addTearDown(api.close);
      await expectLater(
        api.request('POST', 'orders', {}),
        throwsA(
          isA<ApiFailure>().having(
            (e) => e.message,
            'message',
            contains('timed out'),
          ),
        ),
      );
      expect(calls, 1);
    },
  );

  test('malformed validation response is a controlled API failure', () async {
    final api = PartnerApi(
      baseUrl: 'https://example.test/api',
      store: MemoryTokens(),
      client: MockClient(
        (_) async => http.Response('{"errors":{"name":[]}}', 422),
      ),
    );
    addTearDown(api.close);
    await expectLater(
      api.request('GET', 'me'),
      throwsA(isA<ApiFailure>().having((e) => e.status, 'status', 422)),
    );
  });

  test('server errors hide internal exception messages', () async {
    final api = PartnerApi(
      baseUrl: 'https://example.test/api',
      store: MemoryTokens(),
      client: MockClient(
        (_) async => http.Response('{"message":"SQLSTATE secret"}', 500),
      ),
    );
    addTearDown(api.close);
    await expectLater(
      api.request('GET', 'me'),
      throwsA(
        isA<ApiFailure>().having(
          (e) => e.message,
          'message',
          isNot(contains('SQLSTATE')),
        ),
      ),
    );
  });

  testWidgets('recovery screen offers an explicit restart', (tester) async {
    var restarted = false;
    await tester.pumpWidget(
      MaterialApp(home: AppRecoveryScreen(onRestart: () => restarted = true)),
    );
    await tester.tap(find.text('Reopen app'));
    expect(restarted, isTrue);
    expect(
      find.textContaining('Check your orders or delivery status'),
      findsOneWidget,
    );
  });

  test(
    'restored tokens authenticate requests and 401 clears secure session',
    () async {
      final store = MemoryTokens()..token = 'saved-token';
      final api = PartnerApi(
        baseUrl: 'https://example.test/api',
        store: store,
        client: MockClient((request) async {
          expect(request.headers['Authorization'], 'Bearer saved-token');
          return jsonResponse({'message': 'Rider account disabled'}, 401);
        }),
      );
      addTearDown(api.close);
      await api.restore();
      await expectLater(
        api.profile(),
        throwsA(isA<ApiFailure>().having((e) => e.status, 'status', 401)),
      );
      expect(store.token, isNull);
      expect(api.signedIn, isFalse);
    },
  );

  test(
    'pickup uses server revision and preserves state when connection fails',
    () async {
      final order = RiderOrder.fromJson(sampleOrder(revision: 4));
      final api = PartnerApi(
        baseUrl: 'https://example.test/api',
        store: MemoryTokens(),
        client: MockClient((request) async {
          expect(request.url.path, '/api/partner/orders/order-123/pickup');
          expect(jsonDecode(request.body), {'revision': 4, 'stop_id': 'hub'});
          throw http.ClientException('offline');
        }),
      );
      addTearDown(api.close);
      await expectLater(
        api.pickup(order, order.nextStop!),
        throwsA(isA<ApiFailure>()),
      );
      expect(order.nextStop!.pickedUp, isFalse);
      expect(order.revision, 4);
    },
  );

  testWidgets('local OTP signs in and securely stores token', (tester) async {
    final store = MemoryTokens();
    var signedIn = false;
    final api = PartnerApi(
      baseUrl: 'https://example.test/api',
      store: store,
      client: MockClient((request) async {
        if (request.url.path.endsWith('/request')) {
          expect(jsonDecode(request.body), {'phone': '9123456780'});
          return jsonResponse({
            'challenge_id': 'challenge-1',
            'local_code': '654321',
          });
        }
        expect(jsonDecode(request.body), {
          'challenge_id': 'challenge-1',
          'code': '654321',
        });
        return jsonResponse({'token': 'secure-token'});
      }),
    );
    addTearDown(api.close);
    await tester.pumpWidget(
      MaterialApp(
        home: RiderLogin(
          api: api,
          onSignedIn: () => signedIn = true,
          onDemo: () {},
        ),
      ),
    );
    await tester.ensureVisible(find.text('Use a development code'));
    await tester.tap(find.text('Use a development code'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField), '9123456780');
    await tester.tap(find.text('Get sign-in code'));
    await tester.pumpAndSettle();
    expect(
      find.textContaining('Local development code: 654321'),
      findsOneWidget,
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, '6-digit code'),
      '654321',
    );
    await tester.ensureVisible(find.text('Verify & sign in'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Verify & sign in'));
    await tester.pumpAndSettle();
    expect(signedIn, isTrue);
    expect(store.token, 'secure-token');
  });

  testWidgets(
    'order conflict blocks confirmation until fresh order is reviewed',
    (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      var mutations = 0;
      var fetches = 0;
      final api = PartnerApi(
        baseUrl: 'https://example.test/api',
        store: MemoryTokens(),
        client: MockClient((request) async {
          if (request.method == 'GET') {
            fetches++;
            return jsonResponse({'data': sampleOrder(revision: fetches - 1)});
          }
          mutations++;
          return jsonResponse({
            'message':
                'This order changed. Refresh and review the latest details.',
          }, 409);
        }),
      );
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: RiderDelivery(api: api, reference: 'order-123'),
        ),
      );
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('Confirm pickup'),
        150,
        scrollable: find
            .descendant(
              of: find.byType(ListView),
              matching: find.byType(Scrollable),
            )
            .first,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Confirm pickup'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Confirm', findRichText: false));
      await tester.pumpAndSettle();
      expect(mutations, 1);
      expect(
        tester
            .widget<FilledButton>(
              find.widgetWithText(FilledButton, 'Confirm pickup'),
            )
            .onPressed,
        isNull,
      );
      await tester.tap(find.byTooltip('Refresh order'));
      await tester.pumpAndSettle();
      expect(fetches, 2);
      await tester.scrollUntilVisible(
        find.text('Confirm pickup'),
        150,
        scrollable: find
            .descendant(
              of: find.byType(ListView),
              matching: find.byType(Scrollable),
            )
            .first,
      );
      await tester.pumpAndSettle();
      expect(
        tester
            .widget<FilledButton>(
              find.widgetWithText(FilledButton, 'Confirm pickup'),
            )
            .onPressed,
        isNotNull,
      );
    },
  );

  testWidgets('admin-issued password signs in and securely saves the token', (
    tester,
  ) async {
    final store = MemoryTokens();
    var signedIn = false;
    final api = PartnerApi(
      baseUrl: 'https://example.test/api',
      store: store,
      client: MockClient((request) async {
        expect(request.url.path, '/api/partner/auth/login');
        expect(jsonDecode(request.body), {
          'phone': '9123456780',
          'password': 'secure-rider-password',
        });
        return jsonResponse({'token': 'password-session'});
      }),
    );
    addTearDown(api.close);
    await tester.pumpWidget(
      MaterialApp(
        home: RiderLogin(
          api: api,
          onSignedIn: () => signedIn = true,
          onDemo: () {},
        ),
      ),
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'Mobile number'),
      '9123456780',
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'App password'),
      'secure-rider-password',
    );
    await tester.ensureVisible(find.text('Sign in'));
    await tester.tap(find.text('Sign in'));
    await tester.pumpAndSettle();
    expect(signedIn, isTrue);
    expect(store.token, 'password-session');
  });

  test('malformed password sign-in cannot create a session', () async {
    final store = MemoryTokens();
    final api = PartnerApi(
      baseUrl: 'https://example.test/api',
      store: store,
      client: MockClient((_) async => jsonResponse({'token': null})),
    );
    addTearDown(api.close);
    await expectLater(
      api.signIn('9123456780', 'secure-rider-password'),
      throwsA(isA<ApiFailure>()),
    );
    expect(store.token, isNull);
    expect(api.signedIn, isFalse);
  });

  testWidgets(
    'history loads its own feed and account displays assigned areas',
    (tester) async {
      final scopes = <String?>[];
      final api = PartnerApi(
        baseUrl: 'https://example.test/api',
        store: MemoryTokens(),
        client: MockClient((request) async {
          if (request.url.path.endsWith('/notifications')) {
            return jsonResponse({
              'latest_id': 0,
              'cursor': 0,
              'data': [],
              'next_page': null,
            });
          }
          if (request.url.path.endsWith('/me')) {
            return jsonResponse({
              'data': {
                'name': 'Asha',
                'phone': '9123456780',
                'vehicle': 'Scooter',
                'is_online': true,
                'locations': [
                  {
                    'name': 'Rajpur',
                    'city': 'Dehradun',
                    'state': 'Uttarakhand',
                  },
                ],
              },
            });
          }
          scopes.add(request.url.queryParameters['scope']);
          return jsonResponse({
            'data': [
              if (scopes.last == 'history')
                {
                  ...sampleOrder(picked: true),
                  'status': 'delivered',
                  'receipt': 'CASH-42',
                },
            ],
            'next_page': null,
          });
        }),
      );
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: RiderHome(api: api, onSignedOut: () {}),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('History'));
      await tester.pumpAndSettle();
      expect(scopes, ['active', 'history']);
      expect(find.text('Collection receipt: CASH-42'), findsOneWidget);
      await tester.tap(find.text('Account'));
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('Rajpur, Dehradun, Uttarakhand'),
        150,
        scrollable: find
            .descendant(
              of: find.byType(ListView),
              matching: find.byType(Scrollable),
            )
            .first,
      );
      expect(find.text('Rajpur, Dehradun, Uttarakhand'), findsOneWidget);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );

  testWidgets(
    'assigned jobs refresh in the foreground and pause in background',
    (tester) async {
      var fetches = 0;
      final api = PartnerApi(
        baseUrl: 'https://example.test/api',
        store: MemoryTokens(),
        client: MockClient((request) async {
          if (request.url.path.endsWith('/notifications')) {
            return jsonResponse({
              'latest_id': 0,
              'cursor': 0,
              'data': [],
              'next_page': null,
            });
          }
          if (request.url.path.endsWith('/me')) {
            return jsonResponse({
              'data': {
                'name': 'Asha',
                'phone': '9123456780',
                'is_online': true,
              },
            });
          }
          fetches++;
          return jsonResponse({'data': [], 'next_page': null});
        }),
      );
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: RiderHome(api: api, onSignedOut: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(fetches, 1);
      await tester.pump(const Duration(seconds: 30));
      await tester.pumpAndSettle();
      expect(fetches, 2);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await tester.pump(const Duration(seconds: 30));
      expect(fetches, 2);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(fetches, 3);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );

  testWidgets('delivery details refresh after returning from another app', (
    tester,
  ) async {
    var fetches = 0;
    final api = PartnerApi(
      baseUrl: 'https://example.test/api',
      store: MemoryTokens(),
      client: MockClient((_) async {
        fetches++;
        return jsonResponse({'data': sampleOrder(revision: fetches)});
      }),
    );
    addTearDown(api.close);
    await tester.pumpWidget(
      MaterialApp(
        home: RiderDelivery(api: api, reference: 'order-123'),
      ),
    );
    await tester.pumpAndSettle();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(fetches, 2);
  });

  testWidgets('reassigned delivery removes stale customer details', (
    tester,
  ) async {
    var fetches = 0;
    final api = PartnerApi(
      baseUrl: 'https://example.test/api',
      store: MemoryTokens(),
      client: MockClient((_) async {
        fetches++;
        return fetches == 1
            ? jsonResponse({'data': sampleOrder(picked: true)})
            : jsonResponse({'message': 'Not found'}, 404);
      }),
    );
    addTearDown(api.close);
    await tester.pumpWidget(
      MaterialApp(
        home: RiderDelivery(api: api, reference: 'order-123'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Priya'), findsOneWidget);
    await tester.tap(find.byTooltip('Refresh order'));
    await tester.pumpAndSettle();
    expect(find.text('Priya'), findsNothing);
    expect(find.text('order-123'), findsNothing);
    expect(find.textContaining('no longer assigned to you'), findsOneWidget);
  });

  testWidgets('rapid pickup taps open only one confirmation', (tester) async {
    var writes = 0;
    final api = PartnerApi(
      baseUrl: 'https://example.test/api',
      store: MemoryTokens(),
      client: MockClient((request) async {
        if (request.method != 'GET') writes++;
        return jsonResponse({'data': sampleOrder(picked: writes > 0)});
      }),
    );
    addTearDown(api.close);
    await tester.pumpWidget(
      MaterialApp(
        home: RiderDelivery(api: api, reference: 'order-123'),
      ),
    );
    await tester.pumpAndSettle();
    final action = tester
        .widget<FilledButton>(
          find.widgetWithText(FilledButton, 'Confirm pickup'),
        )
        .onPressed!;
    action();
    action();
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog), findsOneWidget);
    await tester.tap(find.text('Confirm', findRichText: false));
    await tester.pumpAndSettle();
    expect(writes, 1);
  });

  testWidgets(
    'restaurant pickup stays disabled until vendor marks the food ready',
    (tester) async {
      var ready = false;
      final api = PartnerApi(
        baseUrl: 'https://example.test/api',
        store: MemoryTokens(),
        client: MockClient((_) async {
          final order = sampleOrder();
          final stop = (order['stops'] as List).first;
          stop['requires_preparation'] = true;
          stop['pickup_ready'] = ready;
          stop['preparation_status'] = ready ? 'ready' : 'preparing';
          return jsonResponse({'data': order});
        }),
      );
      await tester.pumpWidget(
        MaterialApp(
          home: RiderDelivery(api: api, reference: 'order-123'),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Restaurant is preparing your order'), findsOneWidget);
      expect(
        tester
            .widget<FilledButton>(
              find.widgetWithText(FilledButton, 'Confirm pickup'),
            )
            .onPressed,
        isNull,
      );
      ready = true;
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(find.text('Ready for pickup'), findsOneWidget);
      expect(
        tester
            .widget<FilledButton>(
              find.widgetWithText(FilledButton, 'Confirm pickup'),
            )
            .onPressed,
        isNotNull,
      );
      await tester.pumpWidget(const SizedBox.shrink());
      api.close();
    },
  );

  test('prepaid and COD values remain in paise', () {
    final order = RiderOrder.fromJson(sampleOrder());
    expect(money(order.codPaise), '₹125.50');
    final prepaid = RiderOrder.fromJson({...sampleOrder(), 'cod_paise': 0});
    expect(prepaid.codPaise, 0);
  });
}
