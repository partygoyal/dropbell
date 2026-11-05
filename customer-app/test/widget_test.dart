import 'dart:async';
import 'package:dropbell_customer/app_recovery.dart';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:dropbell_customer/customer_api.dart';
import 'package:dropbell_customer/main.dart';
import 'package:dropbell_customer/checkout.dart';
import 'package:dropbell_customer/delivery_pin.dart';
import 'package:dropbell_customer/support.dart';
import 'package:dropbell_customer/recovery.dart';
import 'package:dropbell_customer/ui.dart';
import 'package:dropbell_mobile_notifications/notifications.dart';

class MemoryStorage implements AppStorage {
  final values = <String, String>{};
  bool failWrites = false;
  @override
  Future<String?> read(String key) async => values[key];
  @override
  Future<void> write(String key, String value) async {
    if (failWrites) throw StateError('Storage unavailable');
    values[key] = value;
  }

  @override
  Future<void> delete(String key) async {
    values.remove(key);
  }
}

Map<String, dynamic> productJson({bool sample = false}) => {
  'id': sample ? 2 : 1,
  'name': sample ? 'Sample paneer' : 'Fresh milk',
  'category': 'Dairy',
  'sub_category_id': 1,
  'pack_size': '1 L',
  'price_paise': 10000,
  'is_sample': sample,
  'available': !sample,
  'max_order_quantity': sample ? 0 : 5,
  'description': 'Fresh everyday essentials',
  'care_instructions': 'Keep chilled',
  'image_url': null,
};
Map<String, dynamic> catalogueJson() => {
  'data': [productJson(), productJson(sample: true)],
  'categories': [
    {'id': 1, 'name': 'Dairy'},
  ],
  'sub_categories': [
    {'id': 1, 'name': 'Milk', 'category_id': 1},
  ],
};
Map<String, dynamic> addressJson() => {
  'id': '1',
  'label': 'Home',
  'name': 'Priya',
  'phone': '9123456780',
  'street': '12 Garden Road',
  'city': 'Dehradun',
  'pin': '248001',
  'locality_id': 1,
  'state_id': 1,
  'city_id': 1,
  'locality': 'Rajpur',
  'isDefault': true,
};
Map<String, dynamic> profileJson() => {
  'isDemo': false,
  'name': 'Priya',
  'phone': '9123456780',
  'addresses': [addressJson()],
};
Map<String, dynamic> quoteJson() => {
  'bill': {'subtotal': 11000, 'discount': 0, 'delivery': 2500, 'total': 13500},
  'rules': {
    'mode': 'locality',
    'distance_meters': 2500,
    'rate_paise_per_km': 1000,
    'name': 'Central',
    'fee_paise': 2500,
  },
  'items': [
    {'id': 1, 'unit_price_paise': 11000, 'quantity': 1},
  ],
};
Map<String, dynamic> orderJson() => {
  'id': 'order-123',
  'isSample': false,
  'createdAt': '2026-10-19T09:00:00Z',
  'status': 'received',
  'stage': 0,
  'address': addressJson(),
  'delivery': 'Next available delivery',
  'payment': 'Pay on delivery · pending',
  'paymentStatus': 'pending',
  'invoiceAvailable': false,
  'requests': [],
  'refunded': 0,
  'items': [
    {'id': 1, 'name': 'Fresh milk', 'qty': '1 L', 'price': 110, 'quantity': 1},
  ],
  'bill': quoteJson()['bill'],
};
http.Response response(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

Future<void> reveal(WidgetTester tester, Finder target) async {
  await tester.scrollUntilVisible(
    target,
    200,
    scrollable: find
        .descendant(
          of: find.byType(ListView).last,
          matching: find.byType(Scrollable),
        )
        .first,
  );
  await tester.pumpAndSettle();
}

void phoneSize(WidgetTester tester) {
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
}

void main() {
  test(
    'timeout includes waiting for response headers and does not replay writes',
    () async {
      var calls = 0;
      final api = CustomerApi(
        baseUrl: 'https://example.test/api',
        storage: MemoryStorage(),
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
          isA<ApiError>().having(
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
    final api = CustomerApi(
      baseUrl: 'https://example.test/api',
      storage: MemoryStorage(),
      client: MockClient(
        (_) async => http.Response('{"errors":{"name":[]}}', 422),
      ),
    );
    addTearDown(api.close);
    await expectLater(
      api.request('GET', 'me'),
      throwsA(isA<ApiError>().having((e) => e.status, 'status', 422)),
    );
  });

  test('server errors hide internal exception messages', () async {
    final api = CustomerApi(
      baseUrl: 'https://example.test/api',
      storage: MemoryStorage(),
      client: MockClient(
        (_) async => http.Response('{"message":"SQLSTATE secret"}', 500),
      ),
    );
    addTearDown(api.close);
    await expectLater(
      api.request('GET', 'me'),
      throwsA(
        isA<ApiError>().having(
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

  testWidgets('fast swipes travel less distance with customer scrolling', (
    tester,
  ) async {
    Future<double> scroll(ScrollPhysics physics) async {
      final controller = ScrollController();
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ListView.builder(
              key: UniqueKey(),
              controller: controller,
              physics: physics,
              itemExtent: 80,
              itemCount: 100,
              itemBuilder: (_, i) => Text('Category $i'),
            ),
          ),
        ),
      );
      await tester.fling(find.byType(ListView), const Offset(0, -160), 8000);
      await tester.pumpAndSettle();
      final offset = controller.offset;
      await tester.pumpWidget(const SizedBox());
      controller.dispose();
      return offset;
    }

    final standard = await scroll(const ClampingScrollPhysics());
    final customer = await scroll(const CustomerScrollPhysics());
    expect(customer, lessThan(standard * .8));
    expect(customer, greaterThan(0));
  });

  testWidgets(
    'support concerns show admin replies and a customer reply reopens the conversation',
    (tester) async {
      phoneSize(tester);
      var sent = false, reads = 0;
      final api = CustomerApi(
        baseUrl: 'https://example.test/api',
        storage: MemoryStorage(),
        client: MockClient((request) async {
          if (request.method == 'GET') {
            return response({
              'data': {
                'ticket': {
                  'id': 1,
                  'subject': 'Missing item',
                  'status': sent ? 'open' : 'resolved',
                  'order_reference': null,
                },
                'messages': [
                  {
                    'id': 1,
                    'sender_role': 'customer',
                    'body': 'An item is missing.',
                    'created_at': '2026-10-23 10:00:00',
                  },
                  {
                    'id': 2,
                    'sender_role': 'admin',
                    'body': 'We have resolved your concern.',
                    'created_at': '2026-10-23 10:01:00',
                  },
                  if (sent)
                    {
                      'id': 3,
                      'sender_role': 'customer',
                      'body': 'I still need help.',
                      'created_at': '2026-10-23 10:02:00',
                    },
                ],
                'next_before': null,
              },
            });
          }
          if (request.url.path.endsWith('/read')) {
            reads++;
            return response({'message': 'Read.'});
          }
          expect(request.url.path, '/api/customer/support/tickets/1/messages');
          final payload = jsonDecode(request.body);
          expect(payload['body'], 'I still need help.');
          expect(payload['request_key'], matches(RegExp(r'^[0-9a-f-]{36}$')));
          sent = true;
          return response({'message': 'Message saved.'});
        }),
      );
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(home: SupportConversationPage(api: api, id: 1)),
      );
      await tester.pumpAndSettle();
      expect(find.text('We have resolved your concern.'), findsOneWidget);
      await tester.enterText(find.byType(TextField), 'I still need help.');
      await tester.tap(find.byTooltip('Send reply'));
      await tester.pumpAndSettle();
      expect(sent, true);
      expect(reads, 2);
      expect(find.text('Status: open'), findsOneWidget);
      expect(find.text('I still need help.'), findsOneWidget);
      await tester.pumpWidget(const SizedBox());
    },
  );

  testWidgets(
    'password recovery can be tested locally and confirms a successful reset',
    (tester) async {
      phoneSize(tester);
      final api = CustomerApi(
        baseUrl: 'https://example.test/api',
        storage: MemoryStorage(),
        client: MockClient((request) async {
          if (request.url.path.endsWith('/forgot-password')) {
            return response({
              'message': 'Check your reset link.',
              'local_reset_url':
                  'http://localhost:3000/reset-password?email=customer%40example.test&token=test-reset-token',
            });
          }
          expect(request.url.path, '/api/customer/auth/reset-password');
          final payload = jsonDecode(request.body);
          expect(payload['token'], 'test-reset-token');
          expect(payload['password_confirmation'], 'new-password');
          return response({
            'message': 'Password updated. Sign in with your new password.',
          });
        }),
      );
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(home: PasswordRecoveryPage(api: api)),
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'Email'),
        'customer@example.test',
      );
      await tester.tap(find.text('Send reset link'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.widgetWithText(TextFormField, 'New password'),
        'new-password',
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'Confirm password'),
        'new-password',
      );
      await reveal(tester, find.text('Save new password'));
      await tester.tap(find.text('Save new password'));
      await tester.pumpAndSettle();
      expect(
        find.text('Password updated. Sign in with your new password.'),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'location hierarchy selects a listed locality and clears it when the parent changes',
    (tester) async {
      Map<String, dynamic>? selected;
      final api = CustomerApi(
        baseUrl: 'https://example.test/api',
        storage: MemoryStorage(),
        client: MockClient((request) async {
          expect(request.url.path, '/api/locations');
          return response({
            'data': [
              {
                'id': 1,
                'name': 'Uttarakhand',
                'cities': [
                  {
                    'id': 1,
                    'name': 'Dehradun',
                    'localities': [
                      {'id': 1, 'name': 'Rajpur', 'postal_code': '248001'},
                    ],
                  },
                ],
              },
              {'id': 2, 'name': 'Other State', 'cities': []},
            ],
          });
        }),
      );
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: LocationSelector(
              api: api,
              onChanged: (value) => selected = value,
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>).at(0));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Uttarakhand').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>).at(1));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Dehradun').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>).at(2));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Rajpur').last);
      await tester.pumpAndSettle();
      expect(selected!['locality_id'], 1);
      expect(selected!['state_id'], 1);
      expect(selected!['city_id'], 1);
      await tester.tap(find.byType(DropdownButtonFormField<String>).at(0));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Other State').last);
      await tester.pumpAndSettle();
      expect(selected, isEmpty);
      expect(find.text('Rajpur'), findsNothing);
    },
  );

  testWidgets('Buy adds the selected quantity and respects the product limit', (
    tester,
  ) async {
    final storage = MemoryStorage();
    final cart = ShoppingCart(storage, 'purchase-controls');
    await cart.restore();
    final product = Product(productJson());
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SizedBox(
            width: 240,
            child: ProductPurchaseControls(
              product: product,
              cart: cart,
              onBuy: (quantity) => cart.setQuantity(
                product,
                (cart.lines[product.id]?.quantity ?? 0) + quantity,
              ),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.byTooltip('Increase quantity for Fresh milk'));
    await tester.pump();
    await tester.tap(find.byTooltip('Increase quantity for Fresh milk'));
    await tester.pump();
    await tester.tap(find.byTooltip('Decrease quantity for Fresh milk'));
    await tester.pump();
    await tester.tap(find.text('Buy'));
    await tester.pumpAndSettle();
    expect(cart.lines[product.id]!.quantity, 2);
    expect(find.text('2 in cart'), findsOneWidget);
    await tester.tap(find.byTooltip('Increase quantity for Fresh milk'));
    await tester.pump();
    await tester.tap(find.text('Buy'));
    await tester.pumpAndSettle();
    expect(cart.lines[product.id]!.quantity, 5);
    expect(find.text('Limit reached'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
    cart.dispose();
  });

  testWidgets(
    'bag shows the fee for the selected delivery locality before checkout',
    (tester) async {
      phoneSize(tester);
      final storage = MemoryStorage();
      Map<String, dynamic>? destination;
      final api = CustomerApi(
        baseUrl: 'https://example.test/api',
        storage: storage,
        client: MockClient((request) async {
          destination = Map<String, dynamic>.from(
            jsonDecode(request.body)['address'],
          );
          return response({'data': quoteJson()});
        }),
      );
      api.token = 'signed-in-customer';
      addTearDown(api.close);
      final cart = ShoppingCart(storage, 'bag');
      await cart.setQuantity(Product(productJson()), 1);
      addTearDown(cart.dispose);
      await tester.pumpWidget(
        MaterialApp(
          home: BagPage(
            api: api,
            cart: cart,
            profile: CustomerProfile.fromJson(profileJson()),
            manageAddresses: () async => null,
            checkout: () async {},
          ),
        ),
      );
      await tester.pumpAndSettle();
      await reveal(tester, find.text('₹135.00'));
      expect(destination!['locality_id'], 1);
      expect(destination!.containsKey('latitude'), false);
      expect(find.text('₹25.00'), findsOneWidget);
      expect(find.text('₹135.00'), findsOneWidget);
    },
  );

  testWidgets(
    'notification inbox marks an owned update read and opens its order',
    (tester) async {
      var read = false;
      String? opened;
      final notifications = MobileNotifications(
        audience: 'customer',
        readStorage: (_) async => null,
        writeStorage: (_, _) async {},
        signedIn: () => true,
        foreground: (_) {},
        openOrder: (reference) async => opened = reference,
        request: (method, path, [body]) async {
          if (method == 'PATCH') {
            expect(path, 'notifications/1/read');
            read = true;
            return {};
          }
          return {
            'data': [
              {
                'id': 1,
                'title': 'Order on its way',
                'body': 'Open your order for updates.',
                'read': read,
                'order_reference': 'order-123',
              },
            ],
            'unread_count': read ? 0 : 1,
            'next_page': null,
          };
        },
      );
      addTearDown(notifications.dispose);
      await tester.pumpWidget(
        MaterialApp(home: NotificationInbox(notifications: notifications)),
      );
      await tester.pumpAndSettle();
      expect(find.text('Notifications (1)'), findsOneWidget);
      await tester.tap(find.text('Order on its way'));
      await tester.pumpAndSettle();
      expect(read, isTrue);
      expect(opened, 'order-123');
      expect(find.text('Notifications'), findsOneWidget);
    },
  );

  test(
    'push device identifier persists and signed-out accounts cannot register',
    () async {
      final storage = MemoryStorage();
      var signedIn = true;
      final registrations = <Map<String, dynamic>>[];
      MobileNotifications create() => MobileNotifications(
        audience: 'customer',
        readStorage: storage.read,
        writeStorage: storage.write,
        signedIn: () => signedIn,
        foreground: (_) {},
        openOrder: (_) async {},
        request: (method, path, [body]) async {
          registrations.add(body!);
          return {};
        },
      );
      final first = create();
      await first.register('first-long-device-registration-token');
      await first.dispose();
      final second = create();
      await second.register('rotated-long-device-registration-token');
      expect(
        registrations[0]['installation_id'],
        registrations[1]['installation_id'],
      );
      signedIn = false;
      await second.register('signed-out-device-registration-token');
      expect(registrations.length, 2);
      await second.dispose();
    },
  );

  test(
    'lost checkout response retries the same request after restarting',
    () async {
      final storage = MemoryStorage();
      final created = <String, Map<String, dynamic>>{};
      var calls = 0;
      final api = CustomerApi(
        baseUrl: 'https://example.test/api',
        storage: storage,
        client: MockClient((request) async {
          final payload = Map<String, dynamic>.from(jsonDecode(request.body));
          created.putIfAbsent(payload['request_key'], () => payload);
          calls++;
          if (calls == 1) {
            throw http.ClientException('Response lost after commit');
          }
          expect(payload, created.values.single);
          return response({'data': orderJson()}, 201);
        }),
      );
      addTearDown(api.close);
      final first = CheckoutAttempt(api, '9123456780');
      await expectLater(
        first.submit({
          'items': [
            {'id': 1, 'quantity': 1},
          ],
          'expected_total': 13500,
        }),
        throwsA(isA<ApiError>()),
      );
      final restarted = CheckoutAttempt(api, '9123456780');
      await restarted.restore();
      final result = await restarted.submit({'expected_total': 99999});
      expect(result.id, 'order-123');
      expect(created.length, 1);
      expect(restarted.pending, isNotNull);
      await restarted.finish();
      expect(storage.values[restarted.key], isNull);
    },
  );

  test(
    'rejected price is discarded so the customer can review a new total',
    () async {
      final storage = MemoryStorage();
      final api = CustomerApi(
        baseUrl: 'https://example.test/api',
        storage: storage,
        client: MockClient(
          (request) async =>
              response({'message': 'Prices changed. Review your bag.'}, 422),
        ),
      );
      addTearDown(api.close);
      final attempt = CheckoutAttempt(api, '9123456780');
      await expectLater(
        attempt.submit({'expected_total': 10000}),
        throwsA(isA<ApiError>().having((e) => e.status, 'status', 422)),
      );
      expect(attempt.pending, isNull);
      expect(storage.values[attempt.key], isNull);
    },
  );

  test(
    'cart cleanup is idempotent across restarts and retains newly added items',
    () async {
      final storage = MemoryStorage();
      final product = Product(productJson());
      final cart = ShoppingCart(storage, 'bag');
      await cart.setQuantity(product, 3);
      await cart.consume('request-1', [
        {'id': 1, 'quantity': 2},
      ]);
      final restarted = ShoppingCart(storage, 'bag');
      await restarted.restore();
      await restarted.consume('request-1', [
        {'id': 1, 'quantity': 2},
      ]);
      expect(restarted.lines[1]!.quantity, 1);
      storage.failWrites = true;
      await expectLater(restarted.setQuantity(product, 2), throwsStateError);
      expect(restarted.lines[1]!.quantity, 1);
      await expectLater(
        cart.setQuantity(Product(productJson(sample: true)), 1),
        throwsA(isA<ApiError>()),
      );
      await expectLater(cart.setQuantity(product, 6), throwsA(isA<ApiError>()));
      cart.dispose();
      restarted.dispose();
    },
  );

  test('checkout is not sent if its request ID cannot be saved', () async {
    final storage = MemoryStorage()..failWrites = true;
    var requests = 0;
    final api = CustomerApi(
      baseUrl: 'https://example.test/api',
      storage: storage,
      client: MockClient((request) async {
        requests++;
        return response({'data': orderJson()}, 201);
      }),
    );
    addTearDown(api.close);
    await expectLater(
      CheckoutAttempt(api, '9123456780').submit({'expected_total': 13500}),
      throwsStateError,
    );
    expect(requests, 0);
  });

  test('expired session is cleared and cannot access account data', () async {
    final storage = MemoryStorage();
    final api = CustomerApi(
      baseUrl: 'https://example.test/api',
      storage: storage,
      client: MockClient((request) async {
        expect(request.headers['Authorization'], 'Bearer expired');
        return response({'message': 'Please sign in'}, 401);
      }),
    );
    addTearDown(api.close);
    storage.values[api.tokenKey] = 'expired';
    await api.restore();
    await expectLater(
      api.profile(),
      throwsA(isA<ApiError>().having((e) => e.status, 'status', 401)),
    );
    expect(api.signedIn, isFalse);
    expect(storage.values[api.tokenKey], isNull);
  });

  for (final registering in [false, true]) {
    testWidgets(
      'guest cart survives cancelled authentication and ${registering ? 'signup' : 'login'} before ordering',
      (tester) async {
        phoneSize(tester);
        final storage = MemoryStorage();
        storage.values['customer:https://example.test/api:pin'] = '248001';
        var placements = 0;
        final api = CustomerApi(
          baseUrl: 'https://example.test/api',
          storage: storage,
          client: MockClient((request) async {
            if (request.url.path == '/api/products') {
              expect(request.url.queryParameters['postal_code'], '248001');
              return response(catalogueJson());
            }
            if (request.url.path.endsWith(
              registering ? '/auth/register' : '/auth/login',
            )) {
              final credentials = jsonDecode(request.body);
              expect(credentials['email'], 'customer@example.test');
              if (registering) {
                expect(credentials['password_confirmation'], 'good-password');
                expect(credentials['phone'], '9123456780');
              }
              return response({'token': 'verified-token'});
            }
            expect(request.headers['Authorization'], 'Bearer verified-token');
            if (request.url.path.endsWith('/me')) {
              return response({'data': profileJson()});
            }
            if (request.url.path.endsWith('/quote')) {
              expect(jsonDecode(request.body)['postal_code'], '248001');
              return response({'data': quoteJson()});
            }
            if (request.method == 'POST' &&
                request.url.path.endsWith('/orders')) {
              final payload = jsonDecode(request.body);
              expect(payload['expected_total'], 13500);
              expect(payload['items'], [
                {'id': 1, 'quantity': 1},
              ]);
              expect(payload['payment'], 'cod');
              expect(payload['address']['street'], '12 Garden Road');
              expect(
                payload['request_key'],
                matches(RegExp(r'^[0-9a-f-]{36}$')),
              );
              placements++;
              return response({'data': orderJson()}, 201);
            }
            if (request.url.path.endsWith('/orders')) {
              return response({
                'data': placements == 0 ? [] : [orderJson()],
              });
            }
            if (request.url.path.endsWith('/orders/order-123')) {
              return response({'data': orderJson()});
            }
            throw StateError(
              'Unexpected request: ${request.method} ${request.url}',
            );
          }),
        );
        addTearDown(api.close);
        await tester.pumpWidget(CustomerApp(api: api));
        await tester.pumpAndSettle();
        expect(find.text('Welcome back.'), findsNothing);
        expect(find.byTooltip('Open bag'), findsOneWidget);
        expect(api.signedIn, isFalse);
        expect(find.byType(ProductTile), findsNothing);
        await tester.tap(find.byKey(const ValueKey('section-groceries')));
        await tester.pumpAndSettle();
        expect(find.text('Shop by category'), findsOneWidget);
        expect(
          tester.getTopLeft(find.text('Choose location')).dy,
          lessThan(tester.getTopLeft(find.byType(DropbellLogo)).dy),
        );
        await tester.enterText(
          find.widgetWithText(TextField, 'Search categories'),
          'Milk',
        );
        await tester.pumpAndSettle();
        expect(find.byType(ProductTile), findsOneWidget);
        expect(
          find.text('No matching categories. Try another search.'),
          findsNothing,
        );
        await tester.enterText(
          find.widgetWithText(TextField, 'Search categories'),
          'Dairy',
        );
        await tester.pumpAndSettle();
        await tester.tap(
          find.descendant(
            of: find.byType(CategoryTile),
            matching: find.text('Dairy'),
          ),
        );
        await tester.pumpAndSettle();
        await reveal(tester, find.text('Buy'));
        await tester.tap(find.text('Buy'));
        await tester.pumpAndSettle();
        await tester.tap(find.byTooltip('Open bag'));
        await tester.pumpAndSettle();
        await reveal(tester, find.text('Review bag & checkout'));
        await tester.tap(find.text('Review bag & checkout'));
        await tester.pumpAndSettle();
        expect(find.text('Welcome back.'), findsOneWidget);
        expect(
          find.text(
            'Sign in or create an account to complete your order. Your cart is saved.',
          ),
          findsOneWidget,
        );
        expect(placements, 0);
        expect(api.signedIn, isFalse);
        await tester.pageBack();
        await tester.pumpAndSettle();
        expect(find.text('Fresh milk'), findsOneWidget);
        final guestBag = jsonDecode(
          storage.values['customer:https://example.test/api:bag']!,
        );
        expect(guestBag['lines'], hasLength(1));
        await reveal(tester, find.text('Review bag & checkout'));
        await tester.tap(find.text('Review bag & checkout'));
        await tester.pumpAndSettle();
        if (registering) {
          await reveal(tester, find.text('New customer? Create an account'));
          await tester.tap(find.text('New customer? Create an account'));
          await tester.pumpAndSettle();
          await tester.enterText(
            find.widgetWithText(TextFormField, 'Full name'),
            'Priya',
          );
          await tester.enterText(
            find.widgetWithText(TextFormField, 'Mobile number'),
            '9123456780',
          );
          await tester.enterText(
            find.widgetWithText(TextFormField, 'Confirm password'),
            'good-password',
          );
        }
        await tester.enterText(
          find.widgetWithText(TextFormField, 'Email'),
          'customer@example.test',
        );
        await tester.enterText(
          find.widgetWithText(TextFormField, 'Password'),
          'good-password',
        );
        await reveal(
          tester,
          find.widgetWithText(
            FilledButton,
            registering ? 'Create account' : 'Sign in',
          ),
        );
        await tester.tap(
          find.widgetWithText(
            FilledButton,
            registering ? 'Create account' : 'Sign in',
          ),
        );
        await tester.pumpAndSettle();
        expect(api.signedIn, isTrue);
        await reveal(tester, find.text('Place order · ₹135.00'));
        await tester.tap(find.text('Place order · ₹135.00'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Confirm order'));
        await tester.pumpAndSettle();
        expect(placements, 1);
        expect(find.text('order-123'), findsOneWidget);
        expect(find.text('Order received'), findsOneWidget);
        final savedBag = jsonDecode(
          storage.values['customer:https://example.test/api:bag']!,
        );
        expect(savedBag['lines'], isEmpty);
      },
    );
  }

  testWidgets(
    'changing coupon invalidates quote until server reviews it again',
    (tester) async {
      phoneSize(tester);
      final storage = MemoryStorage();
      final api = CustomerApi(
        baseUrl: 'https://example.test/api',
        storage: storage,
        client: MockClient((request) async => response({'data': quoteJson()})),
      );
      addTearDown(api.close);
      final cart = ShoppingCart(storage, 'bag');
      await cart.setQuantity(Product(productJson()), 1);
      addTearDown(cart.dispose);
      await tester.pumpWidget(
        MaterialApp(
          home: CheckoutPage(
            api: api,
            profile: CustomerProfile.fromJson(profileJson()),
            cart: cart,
            manageAddresses: () async => null,
          ),
        ),
      );
      await tester.pumpAndSettle();
      await reveal(tester, find.text('Place order · ₹135.00'));
      expect(find.text('Place order · ₹135.00'), findsOneWidget);
      await tester.enterText(
        find.widgetWithText(TextField, 'Coupon code (optional)'),
        'DROP10',
      );
      await tester.pumpAndSettle();
      expect(find.text('Place order · ₹135.00'), findsNothing);
      await tester.tap(find.text('Review final total'));
      await tester.pumpAndSettle();
      await reveal(tester, find.text('Place order · ₹135.00'));
      expect(find.text('Place order · ₹135.00'), findsOneWidget);
    },
  );
}
