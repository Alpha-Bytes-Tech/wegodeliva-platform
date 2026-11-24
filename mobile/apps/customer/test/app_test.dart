import 'package:customer_app/app.dart';
import 'package:customer_app/location/delivery_location.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wegodeliva_auth/wegodeliva_auth.dart';

import 'support/harness.dart';

void main() {
  testWidgets('offers sign-up from the login screen', (tester) async {
    final h = Harness();
    await tester.pumpWidget(h.app);
    await tester.pumpAndSettle();

    expect(find.text('Sign in'), findsOneWidget);
    await tester.tap(find.text('New to WeGoDeliva? Create an account'));
    await tester.pumpAndSettle();
    expect(find.text('Create your account'), findsOneWidget);
    await tester.tap(find.text('Read the terms'));
    await tester.pumpAndSettle();
    // Terms open even while signed out.
    expect(find.byType(Scaffold), findsWidgets);
    expect(
      h.container.read(routerProvider).state.matchedLocation,
      '/pages/terms',
    );
  });

  testWidgets('keeps a referral code from a link for sign-up', (tester) async {
    final h = Harness();
    await tester.pumpWidget(h.app);
    await tester.pumpAndSettle();

    h.container.read(routerProvider).go('/r/ada12');
    await tester.pumpAndSettle();

    expect(h.container.read(pendingReferralProvider), 'ADA12');
    expect(find.text('Sign in'), findsOneWidget);
  });

  testWidgets(
    'opens on Home with the default address, and moves between tabs',
    (tester) async {
      final h = Harness(token: '1|token');
      await tester.pumpWidget(h.app);
      await tester.pumpAndSettle();

      expect(find.text('Hello, Ada'), findsOneWidget);
      expect(find.text('Home'), findsWidgets);
      expect(
        find.bySemanticsLabel('Delivering to Home. Tap to change.'),
        findsOneWidget,
      );

      await tester.tap(
        find.descendant(
          of: find.byType(NavigationBar),
          matching: find.text('Search'),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Find food, groceries and more'), findsOneWidget);

      for (final tab in ['Orders', 'Wallet']) {
        await tester.tap(
          find.descendant(
            of: find.byType(NavigationBar),
            matching: find.text(tab),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.text('Coming soon'), findsOneWidget, reason: tab);
      }
    },
  );

  testWidgets(
    'changes the delivery location to a saved address or the current position',
    (tester) async {
      final location = FakeLocationService(null);
      final h = Harness(token: '1|token', location: location);
      await tester.pumpWidget(h.app);
      await tester.pumpAndSettle();

      await tester.tap(
        find.bySemanticsLabel('Delivering to Home. Tap to change.'),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Office'));
      await tester.pumpAndSettle();
      expect(
        find.bySemanticsLabel('Delivering to Office. Tap to change.'),
        findsOneWidget,
      );
      expect(
        h.store.values[DeliveryLocationController.storeKey],
        contains('a-2'),
      );

      await tester.tap(
        find.bySemanticsLabel('Delivering to Office. Tap to change.'),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Use my current location'));
      await tester.pumpAndSettle();
      expect(find.textContaining("couldn't get your location"), findsOneWidget);

      location.position = (lat: 6.6, lng: 3.35);
      await tester.tap(find.text('Use my current location'));
      await tester.pumpAndSettle();
      expect(
        find.bySemanticsLabel('Delivering to Current location. Tap to change.'),
        findsOneWidget,
      );
    },
  );

  testWidgets('remembers the chosen location between launches', (tester) async {
    final h = Harness(token: '1|token');
    h.store.values[DeliveryLocationController.storeKey] =
        '{"label":"Hostel","lat":6.5,"lng":3.4,"address_id":null,"detail":null}';
    await tester.pumpWidget(h.app);
    await tester.pumpAndSettle();

    expect(
      find.bySemanticsLabel('Delivering to Hostel. Tap to change.'),
      findsOneWidget,
    );
  });

  testWidgets('profile: dark mode, support, legal pages and sign out', (
    tester,
  ) async {
    final h = Harness(
      token: '1|token',
      appSettings: settings(latest: '1.1.0'),
    );
    await tester.pumpWidget(h.app);
    await tester.pumpAndSettle();
    await tester.tap(
      find.descendant(
        of: find.byType(NavigationBar),
        matching: find.text('Profile'),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('ada@example.com'), findsOneWidget);
    expect(find.text('A new version is available'), findsOneWidget);
    await tester.tap(find.text('Dark'));
    await tester.pumpAndSettle();
    expect(
      tester.widget<MaterialApp>(find.byType(MaterialApp)).themeMode,
      ThemeMode.dark,
    );
    expect(h.store.values['theme_mode'], 'dark');

    await tester.tap(find.text('Chat with support on WhatsApp'));
    expect(h.opened.single.toString(), 'https://wa.me/2348012345678');

    await tester.ensureVisible(find.text('Sign out'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Sign out'));
    await tester.pumpAndSettle();
    expect(find.text('Sign in'), findsOneWidget);
    expect(h.repository.logouts, 1);
  });

  testWidgets('blocks an outdated app with "update required"', (tester) async {
    final h = Harness(
      token: '1|token',
      appSettings: settings(minVersion: '2.0.0'),
    );
    await tester.pumpWidget(h.app);
    await tester.pumpAndSettle();

    expect(find.text('Please update WeGoDeliva'), findsOneWidget);
    await tester.tap(find.text('Update now'));
    expect(h.opened.single.toString(), 'https://play.example/wgd');
  });

  testWidgets('opens a store link once the session is restored', (
    tester,
  ) async {
    final h = Harness(token: '1|token');
    await tester.pumpWidget(h.app);
    await tester.pumpAndSettle();

    h.container.read(routerProvider).go('/v/mama-put');
    await tester.pumpAndSettle();
    expect(find.text('Mama Put Kitchen'), findsWidgets);
    expect(find.text('Menu'), findsOneWidget);
  });
}
