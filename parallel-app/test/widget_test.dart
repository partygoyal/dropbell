import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:dropbell_partner/main.dart';
import 'package:dropbell_partner/delivery.dart';

void main() {
  testWidgets('dashboard scrolls upward without stretching at the top', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(const PartnerApp(demo: true));
    await tester.tap(find.text('Explore rider demo →'));
    await tester.pumpAndSettle();
    final scrollable = find
        .descendant(
          of: find.byType(ListView),
          matching: find.byType(Scrollable),
        )
        .first;
    final state = tester.state<ScrollableState>(scrollable);
    await tester.drag(find.byType(ListView), const Offset(0, 250));
    await tester.pumpAndSettle();
    expect(state.position.pixels, 0);
    expect(find.byType(StretchingOverscrollIndicator), findsNothing);
    await tester.drag(find.byType(ListView), const Offset(0, -450));
    await tester.pumpAndSettle();
    expect(state.position.pixels, greaterThan(0));
    expect(find.text('View delivery →').first.hitTestable(), findsOneWidget);
  });

  test('delivery requires sequential pickups and confirmed COD', () {
    final delivery = demoDeliveries().first;
    expect(
      () => delivery.complete('CASH2042', paymentCollected: true),
      throwsStateError,
    );
    delivery.confirmPickup();
    expect(delivery.nextStop!.name, 'Green Basket Store');
    expect(
      () => delivery.complete('CASH2042', paymentCollected: true),
      throwsStateError,
    );
    delivery.confirmPickup();
    expect(
      () => delivery.complete(' ', paymentCollected: true),
      throwsStateError,
    );
    expect(
      () => delivery.complete('CASH2042', paymentCollected: false),
      throwsStateError,
    );
    delivery.complete(' CASH2042 ', paymentCollected: true);
    expect(delivery.delivered, isTrue);
    expect(delivery.receipt, 'CASH2042');
    expect(
      () => delivery.complete('OTHER', paymentCollected: true),
      throwsStateError,
    );
  });

  testWidgets('rider can complete multi-stop delivery and see history', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(const PartnerApp(demo: true));
    await tester.tap(find.text('Explore rider demo →'));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('View delivery →').first, 200);
    await tester.pumpAndSettle();
    await tester.tap(find.text('View delivery →').first);
    await tester.pumpAndSettle();
    for (var i = 0; i < 2; i++) {
      await tester.ensureVisible(find.text('Confirm pickup'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Confirm pickup'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Collected'));
      await tester.pumpAndSettle();
    }
    final deliveryScroll = find
        .descendant(
          of: find.byType(ListView),
          matching: find.byType(Scrollable),
        )
        .first;
    await tester.scrollUntilVisible(
      find.byType(TextFormField),
      200,
      scrollable: deliveryScroll,
    );
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField), 'CASH2042');
    await tester.scrollUntilVisible(
      find.byType(CheckboxListTile),
      150,
      scrollable: deliveryScroll,
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byType(CheckboxListTile));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(
      find.text('Confirm delivery & collection'),
      150,
      scrollable: deliveryScroll,
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Confirm delivery & collection'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('History'));
    await tester.pumpAndSettle();
    expect(find.text('DB2042'), findsOneWidget);
    expect(find.text('Receipt: CASH2042'), findsOneWidget);
  });
}
