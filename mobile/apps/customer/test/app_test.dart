import 'package:customer_app/app.dart';
import 'package:customer_app/location/delivery_location.dart';
import 'package:customer_app/update/update_gate.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wegodeliva_auth/wegodeliva_auth.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

class FakeAuthRepository implements AuthRepository {
  int logouts = 0;

  @override
  ClientApp get app => ClientApp.customer;

  @override
  Future<AuthUser> me() async => const AuthUser(
    uuid: 'u-1',
    name: 'Ada Obi',
    email: 'ada@example.com',
    phone: '+2348031234567',
    status: 'active',
  );

  @override
  Future<void> logout() async => logouts++;

  @override
  Future<OtpTiming> sendOtp(String email) async =>
      const OtpTiming(expiresIn: 600, resendIn: 60);

  @override
  Future<SignUpResult> register({
    required String name,
    required String email,
    required String phone,
    String? referralCode,
  }) async => const SignUpResult(
    timing: OtpTiming(expiresIn: 600, resendIn: 60),
    referralApplied: false,
  );

  @override
  Future<AuthSession> verifyOtp(
    String email,
    String code, {
    String? deviceName,
  }) => throw UnimplementedError();
}

class FakeLocationService implements LocationService {
  FakeLocationService(this.position);

  ({double lat, double lng})? position;

  @override
  Future<({double lat, double lng})?> current() async => position;
}

const home = SavedAddress(
  id: 'a-1',
  label: 'Home',
  line1: '1 Test Street',
  landmark: 'By the mosque',
  lat: 6.5,
  lng: 3.38,
  isDefault: true,
);
const office = SavedAddress(
  id: 'a-2',
  label: 'Office',
  line1: '2 Work Road',
  lat: 6.45,
  lng: 3.4,
);

AppSettings settings({String minVersion = '1.0.0', String latest = '1.0.0'}) =>
    AppSettings.fromJson({
      'app': {
        'min_version': minVersion,
        'latest_version': latest,
        'store_urls': {'android': 'https://play.example/wgd'},
      },
      'support': {'whatsapp': '+234 801 234 5678'},
      'pages': [
        {'slug': 'terms', 'title': 'Terms of use'},
      ],
    });

class Harness {
  Harness({String? token, AppSettings? appSettings, LocationService? location})
    : storage = InMemoryTokenStorage(token),
      store = InMemoryKeyValueStore(),
      repository = FakeAuthRepository(),
      opened = [] {
    container = ProviderContainer(
      overrides: [
        appConfigProvider.overrideWithValue(
          AppConfig.forFlavor(
            flavor: Flavor.dev,
            app: ClientApp.customer,
            appVersion: '1.0.0',
          ),
        ),
        tokenStorageProvider.overrideWithValue(storage),
        authRepositoryProvider.overrideWithValue(repository),
        loggerProvider.overrideWithValue(AppLogger(enabled: false)),
        keyValueStoreProvider.overrideWithValue(store),
        appSettingsProvider.overrideWith(
          (ref) async => appSettings ?? settings(),
        ),
        savedAddressesProvider.overrideWith(
          (ref) async => const [office, home],
        ),
        locationServiceProvider.overrideWithValue(
          location ?? FakeLocationService((lat: 6.6, lng: 3.35)),
        ),
        linkOpenerProvider.overrideWithValue((uri) async {
          opened.add(uri);
          return true;
        }),
      ],
    );
  }

  final InMemoryTokenStorage storage;
  final InMemoryKeyValueStore store;
  final FakeAuthRepository repository;
  final List<Uri> opened;
  late final ProviderContainer container;

  Widget get app => UncontrolledProviderScope(
    container: container,
    child: const CustomerApp(),
  );
}

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

      for (final tab in ['Search', 'Orders', 'Wallet']) {
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

  testWidgets('opens a store link after sign-in restores the session', (
    tester,
  ) async {
    final h = Harness(token: '1|token');
    await tester.pumpWidget(h.app);
    await tester.pumpAndSettle();

    h.container.read(routerProvider).go('/v/mama-put');
    await tester.pumpAndSettle();
    expect(find.text('Store'), findsOneWidget);
    expect(
      find.text('This link opens in an upcoming version of the app.'),
      findsOneWidget,
    );
  });
}
