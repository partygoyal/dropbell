import 'dart:convert';
import 'package:dropbell_customer/customer_api.dart';
import 'package:dropbell_customer/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'widget_test.dart' as fixtures;

Map<String, dynamic> foodCatalogue() => {
  'data': [
    fixtures.productJson(),
    {
      ...fixtures.productJson(),
      'id': 10,
      'name': 'Paneer masala',
      'category': 'North Indian',
      'shopping_section': 'food',
    },
  ],
  'categories': [
    {'id': 1, 'name': 'Dairy'},
    {'id': 2, 'name': 'North Indian', 'shopping_section': 'food'},
  ],
  'sub_categories': [],
  'vendors': [
    {
      'id': 7,
      'name': 'Test kitchen',
      'city': 'Dehradun',
      'sections': ['food'],
      'cuisines': ['North Indian'],
      'product_count': 1,
      'section_counts': {'food': 1},
    },
  ],
};

void main() {
  test('catalogue separates sections and preserves legacy groceries', () {
    final catalogue = Catalogue.fromJson(foodCatalogue());
    expect(catalogue.categoriesFor('food'), ['North Indian']);
    expect(catalogue.categoriesFor('groceries'), ['Dairy']);
    expect(catalogue.products.map((p) => p.section), ['groceries', 'food']);
  });

  test('restaurant menu and quote carry the selected vendor', () async {
    final api = CustomerApi(
      baseUrl: 'https://example.test/api',
      storage: fixtures.MemoryStorage(),
      client: MockClient((request) async {
        if (request.method == 'GET') {
          expect(request.url.queryParameters, {
            'postal_code': '248001',
            'locality_id': '12',
            'vendor_id': '7',
          });
          return http.Response(jsonEncode(foodCatalogue()), 200);
        }
        expect(jsonDecode(request.body)['vendor_id'], 7);
        return http.Response(jsonEncode({'data': fixtures.quoteJson()}), 200);
      }),
    );
    final menu = await api.catalogue('248001', locality: '12', vendorId: 7);
    expect(menu.products.every((p) => p.vendorId == 7), isTrue);
    await api.quote(
      [
        {'id': 10, 'quantity': 1},
      ],
      '248001',
      '',
      fixtures.addressJson(),
      7,
    );
    api.close();
  });

  test(
    'restaurant bag survives restart, rejects mixing and protects another shop during recovery',
    () async {
      final storage = fixtures.MemoryStorage();
      final first = Product({
        ...fixtures.productJson(),
        'selected_vendor_id': 7,
      });
      final other = Product({
        ...fixtures.productJson(),
        'selected_vendor_id': 8,
      });
      final cart = ShoppingCart(storage, 'bag');
      await cart.setQuantity(first, 2);
      final restored = ShoppingCart(storage, 'bag');
      await restored.restore();
      expect(restored.vendorId, 7);
      await expectLater(
        restored.setQuantity(other, 1),
        throwsA(isA<ApiError>()),
      );
      expect(restored.count, 2);
      storage.failWrites = true;
      await expectLater(restored.clear(), throwsStateError);
      expect(restored.count, 2);
      storage.failWrites = false;
      await restored.clear();
      await restored.setQuantity(other, 1);
      await restored.consume('old-checkout', [
        {'id': 1, 'quantity': 2},
      ], 7);
      expect(restored.count, 1);
      await restored.consume('new-checkout', [
        {'id': 1, 'quantity': 1},
      ], 8);
      expect(restored.count, 0);
    },
  );

  testWidgets(
    'Food opens first, Groceries filters categories, restaurant menu uses scoped inventory',
    (tester) async {
      final storage = fixtures.MemoryStorage();
      storage.values['customer:https://example.test/api:pin'] = '248001';
      var menuRequests = 0;
      final api = CustomerApi(
        baseUrl: 'https://example.test/api',
        storage: storage,
        client: MockClient((request) async {
          if (request.url.path.endsWith('/promotions')) {
            return http.Response('{"data":[]}', 200);
          }
          final body = foodCatalogue();
          if (request.url.queryParameters['vendor_id'] == '7') {
            menuRequests++;
            body['data'] = (body['data'] as List)
                .where((p) => p['shopping_section'] == 'food')
                .toList();
          }
          return http.Response(jsonEncode(body), 200);
        }),
      );
      await tester.pumpWidget(CustomerApp(api: api));
      await tester.pumpAndSettle();
      expect(find.text('What are you craving?'), findsOneWidget);
      expect(find.text('North Indian'), findsWidgets);
      expect(find.text('Dairy'), findsNothing);
      await tester.tap(find.byKey(const ValueKey('section-groceries')));
      await tester.pumpAndSettle();
      expect(find.text('Dairy'), findsOneWidget);
      expect(find.text('Test kitchen'), findsNothing);
      await tester.tap(find.byKey(const ValueKey('section-food')));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Test kitchen'));
      await tester.tap(find.text('Test kitchen'));
      await tester.pumpAndSettle();
      expect(menuRequests, 1);
      expect(find.text('Paneer masala'), findsOneWidget);
      expect(find.text('Fresh milk'), findsNothing);
      await tester.pumpWidget(const SizedBox.shrink());
      api.close();
    },
  );
}
