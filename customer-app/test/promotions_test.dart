import 'dart:convert';
import 'package:dropbell_customer/customer_api.dart';
import 'package:dropbell_customer/promotions.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('offers auto-scroll, pause and support manual navigation', (
    tester,
  ) async {
    final api = CustomerApi(
      baseUrl: 'https://example.test/api',
      client: MockClient(
        (_) async => http.Response(
          jsonEncode({
            'data': [
              {
                'id': 1,
                'title': 'First offer',
                'description': 'Save today',
                'code': 'SAVE10',
              },
              {
                'id': 2,
                'title': 'Second offer',
                'description': 'Weekend discount',
                'code': 'SAVE20',
              },
            ],
          }),
          200,
          headers: {'content-type': 'application/json'},
        ),
      ),
    );
    addTearDown(api.close);
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(body: PromotionCarousel(api: api)),
      ),
    );
    await tester.pumpAndSettle();
    final view = tester.widget<PageView>(find.byType(PageView));
    expect(view.controller!.page, 0);
    await tester.pump(const Duration(seconds: 6));
    await tester.pumpAndSettle();
    expect(view.controller!.page, 1);
    await tester.tap(find.byTooltip('Pause automatic offers'));
    await tester.pump();
    await tester.pump(const Duration(seconds: 7));
    expect(view.controller!.page, 1);
    await tester.tap(find.byTooltip('Previous offer'));
    await tester.pumpAndSettle();
    expect(view.controller!.page, 0);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('unavailable promotions leave shopping usable', (tester) async {
    final api = CustomerApi(
      baseUrl: 'https://example.test/api',
      client: MockClient((_) async => http.Response('{}', 503)),
    );
    addTearDown(api.close);
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Column(
            children: [
              PromotionCarousel(api: api),
              const Text('Shop products'),
            ],
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Shop products'), findsOneWidget);
    expect(find.byType(PageView), findsNothing);
    await tester.pumpWidget(const SizedBox.shrink());
  });
}
