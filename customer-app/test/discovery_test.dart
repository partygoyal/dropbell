import 'dart:convert';
import 'package:dropbell_customer/customer_api.dart';
import 'package:dropbell_customer/discovery.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'widget_test.dart' as fixtures;

void main() {
  testWidgets(
    'dish options validate required choices and persist server-priced option identities',
    (tester) async {
      Product? result;
      final product = Product({
        ...fixtures.productJson(),
        'option_vendor_id': 7,
        'menu_revision': 2,
        'option_groups': [
          {
            'name': 'Size',
            'min': 1,
            'max': 1,
            'choices': [
              {'id': 'regular', 'name': 'Regular', 'price_paise': 0},
              {'id': 'large', 'name': 'Large', 'price_paise': 5000},
            ],
          },
        ],
      });
      await tester.pumpWidget(
        MaterialApp(
          home: Builder(
            builder: (context) => Scaffold(
              body: TextButton(
                onPressed: () async {
                  result = await chooseDishOptions(context, product, null);
                },
                child: const Text('Customize'),
              ),
            ),
          ),
        ),
      );
      await tester.tap(find.text('Customize'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Use these options'));
      await tester.pumpAndSettle();
      expect(find.text('Choose 1–1 options for Size.'), findsOneWidget);
      await tester.tap(find.text('Large · +₹50.00'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Use these options'));
      await tester.pumpAndSettle();
      expect(result!.price, 15000);
      expect(result!.vendorId, 7);
      expect(CartLine(result!, 1).item, {
        'id': 1,
        'quantity': 1,
        'vendor_id': 7,
        'options': ['large'],
        'menu_revision': 2,
      });
    },
  );
  test(
    'recovery preserves different option selections and mixed-shop reorders retain individual suppliers',
    () async {
      final store = fixtures.MemoryStorage(),
          cart = ShoppingCart(fixtures.MemoryStorage(), 'bag');
      final first = Product({
        ...fixtures.productJson(),
        'selected_vendor_id': 7,
        'selected_option_ids': ['large'],
        'menu_revision': 2,
      });
      await cart.setQuantity(first, 1);
      await cart.consume('old', [
        {
          'id': 1,
          'quantity': 1,
          'options': ['regular'],
          'vendor_id': 7,
          'menu_revision': 1,
        },
      ], 7);
      expect(cart.count, 1);
      final other = Product({
        ...fixtures.productJson(),
        'id': 2,
        'selected_vendor_id': 8,
      });
      await store.write(
        'mixed',
        cart.encode({1: CartLine(first, 1), 2: CartLine(other, 1)}, {}),
      );
      final restored = ShoppingCart(store, 'mixed');
      await restored.restore();
      expect(restored.vendorId, isNull);
      await restored.setQuantity(first, 2);
      expect(restored.lines[1]!.quantity, 2);
    },
  );
  testWidgets(
    'favourites use the authenticated account and persist the requested target',
    (tester) async {
      var saved = false;
      final api = CustomerApi(
        baseUrl: 'https://example.test/api',
        storage: fixtures.MemoryStorage(),
        client: MockClient((r) async {
          if (r.method == 'PUT') {
            final body = jsonDecode(r.body);
            expect(body['kind'], 'product');
            expect(body['target_id'], 1);
            saved = body['saved'];
          }
          return http.Response(
            jsonEncode({
              'data': saved
                  ? [
                      {'kind': 'product', 'target_id': 1},
                    ]
                  : [],
            }),
            200,
          );
        }),
      );
      api.token = 'customer';
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(body: FavouriteButton(api: api, id: 1)),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();
      expect(saved, isTrue);
      expect(find.text('Saved'), findsOneWidget);
      await tester.pumpWidget(const SizedBox.shrink());
      api.close();
    },
  );
}
