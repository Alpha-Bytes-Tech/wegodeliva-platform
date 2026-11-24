import 'dart:async';

import 'package:customer_app/app.dart';
import 'package:customer_app/catalogue/models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_catalogue.dart';
import 'support/harness.dart';

Future<Harness> signedIn(
  WidgetTester tester, {
  FakeCatalogue? catalogue,
}) async {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final h = Harness(token: '1|token', catalogue: catalogue);
  await tester.pumpWidget(h.app);
  await tester.pumpAndSettle();
  return h;
}

Future<void> tapOn(WidgetTester tester, Finder finder) async {
  await tester.ensureVisible(finder);
  await tester.pumpAndSettle();
  await tester.tap(finder);
  await tester.pumpAndSettle();
}

void main() {
  testWidgets(
    'Home shows store types, featured stores and stores open near you',
    (tester) async {
      await signedIn(tester);

      expect(find.text('Food'), findsOneWidget);
      expect(find.text('Pharmacy'), findsOneWidget);
      expect(find.text('Featured'), findsOneWidget);
      expect(find.text('Open near you'), findsOneWidget);
      expect(
        find.text('Mama Put Kitchen'),
        findsNWidgets(2),
      ); // featured and open
      expect(find.text('Pharma Plus'), findsOneWidget);
      expect(find.text('Suya Spot'), findsNothing); // closed
      expect(find.text('850 m away'), findsWidgets);
      expect(find.text(' 4.6 (120)'), findsWidgets);
    },
  );

  testWidgets('Home says when stores cannot load, and retries', (tester) async {
    final catalogue = FakeCatalogue()..failVendors = true;
    await signedIn(tester, catalogue: catalogue);
    expect(find.text("Couldn't load stores."), findsOneWidget);

    catalogue.failVendors = false;
    await tester.tap(find.text('Try again'));
    await tester.pumpAndSettle();
    expect(find.text('Open near you'), findsOneWidget);
  });

  testWidgets(
    'a store type lists its stores with open-now, rating and category filters',
    (tester) async {
      await signedIn(tester);
      await tester.tap(find.text('Food'));
      await tester.pumpAndSettle();

      expect(find.text('Mama Put Kitchen'), findsOneWidget);
      expect(find.text('Suya Spot'), findsOneWidget);
      expect(find.text('Pharma Plus'), findsNothing);

      await tapOn(tester, find.widgetWithText(ChoiceChip, 'Top rated'));
      await tester.pumpAndSettle();
      expect(
        tester.getTopLeft(find.text('Suya Spot')).dy,
        lessThan(tester.getTopLeft(find.text('Mama Put Kitchen')).dy),
      );

      await tapOn(tester, find.widgetWithText(FilterChip, 'Open now'));
      await tester.pumpAndSettle();
      expect(find.text('Suya Spot'), findsNothing);

      await tapOn(tester, find.widgetWithText(FilterChip, 'Open now'));
      await tapOn(tester, find.widgetWithText(ChoiceChip, 'Rice dishes'));
      await tester.pumpAndSettle();
      expect(find.text('Mama Put Kitchen'), findsOneWidget);
      expect(find.text('Suya Spot'), findsNothing);
    },
  );

  testWidgets(
    'a store shows its fee, menu sections, info and reviews, and can be saved',
    (tester) async {
      final h = await signedIn(tester);
      unawaited(h.container.read(routerProvider).push('/v/mama-put'));
      await tester.pumpAndSettle();

      expect(find.text('Delivery ₦500'), findsOneWidget);
      expect(find.text('30–40 min'), findsOneWidget);
      expect(find.text('Minimum order ₦1,000'), findsOneWidget);
      expect(find.text('Jollof rice'), findsOneWidget);
      expect(find.text('Sold out'), findsOneWidget);

      await tester.tap(find.text('Drinks'));
      await tester.pumpAndSettle();
      expect(find.text('Jollof rice'), findsNothing);
      expect(find.text('Zobo'), findsOneWidget);

      await tester.tap(find.byTooltip('Save to favourites'));
      await tester.pumpAndSettle();
      expect(h.catalogue.favouriteCalls, ['+vendor:v-1']);
      expect(find.byTooltip('Remove from favourites'), findsOneWidget);

      await tester.tap(find.text('Info'));
      await tester.pumpAndSettle();
      expect(find.text('12 Herbert Macaulay Way, Yaba'), findsOneWidget);
      expect(find.text('08:00–22:00'), findsOneWidget);

      await tester.tap(find.text('Reviews'));
      await tester.pumpAndSettle();
      expect(find.textContaining('Best jollof in Yaba'), findsOneWidget);
      expect(find.text('★★★★★'), findsOneWidget);
    },
  );

  testWidgets('a product needs its required options and shows a live total', (
    tester,
  ) async {
    final h = await signedIn(tester);
    unawaited(h.container.read(routerProvider).push('/p/p-1'));
    await tester.pumpAndSettle();

    expect(find.text('₦1,800'), findsOneWidget); // crossed-out original
    expect(find.text('Choose 1'), findsOneWidget);
    expect(find.text('Up to 2'), findsOneWidget);

    await tester.tap(find.text('Add to cart · ₦1,500'));
    await tester.pumpAndSettle();
    expect(h.addedToCart, isEmpty);

    await tapOn(tester, find.text('Chicken'));
    await tapOn(tester, find.text('Plantain'));
    await tapOn(tester, find.text('Egg'));
    await tapOn(
      tester,
      find.text('Salad'),
    ); // a third extra is ignored (up to 2)
    await tester.pumpAndSettle();
    expect(find.text('Add to cart · ₦2,300'), findsOneWidget);

    await tapOn(tester, find.text('Beef')); // single choice: replaces chicken
    await tester.tap(find.byTooltip('Increase quantity'));
    await tester.pumpAndSettle();
    expect(find.text('Add to cart · ₦4,200'), findsOneWidget);

    await tester.tap(find.text('Add to cart · ₦4,200'));
    expect(h.addedToCart, [420000]);
  });

  testWidgets('search finds stores and products and remembers the search', (
    tester,
  ) async {
    final h = await signedIn(tester);
    await tester.tap(
      find.descendant(
        of: find.byType(NavigationBar),
        matching: find.text('Search'),
      ),
    );
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField), 'jollof');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(find.text('Mama Put Kitchen'), findsOneWidget);
    await tester.tap(find.text('Products'));
    await tester.pumpAndSettle();
    expect(find.text('Jollof rice'), findsOneWidget);
    expect(h.store.values['recent_searches'], '["jollof"]');

    await tester.enterText(find.byType(TextField), 'pizza');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(find.text('Nothing found for "pizza"'), findsWidgets);
    expect(h.catalogue.searches, ['jollof', 'pizza']);
  });

  testWidgets('favourites list saved stores and products', (tester) async {
    final catalogue = FakeCatalogue()
      ..savedFavourites = const SearchResults(vendors: [mamaPut]);
    final h = await signedIn(tester, catalogue: catalogue);
    unawaited(h.container.read(routerProvider).push('/favourites'));
    await tester.pumpAndSettle();

    expect(find.text('Mama Put Kitchen'), findsOneWidget);
    await tester.tap(find.text('Products'));
    await tester.pumpAndSettle();
    expect(find.text('No saved products yet.'), findsOneWidget);
  });
}
