import 'package:customer_app/app.dart';
import 'package:customer_app/catalogue/catalogue_providers.dart';
import 'package:customer_app/catalogue/catalogue_repository.dart';
import 'package:customer_app/discover/product_screen.dart';
import 'package:customer_app/location/delivery_location.dart';
import 'package:customer_app/update/update_gate.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

import 'fake_catalogue.dart';

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
  Harness({
    String? token,
    AppSettings? appSettings,
    LocationService? location,
    FakeCatalogue? catalogue,
  }) : catalogue = catalogue ?? FakeCatalogue(),
       storage = InMemoryTokenStorage(token),
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
        catalogueRepositoryProvider.overrideWithValue(this.catalogue),
        bannersProvider.overrideWith((ref, type) async => const []),
        addToCartProvider.overrideWithValue(
          (_, selection) => addedToCart.add(selection.totalKobo),
        ),
        linkOpenerProvider.overrideWithValue((uri) async {
          opened.add(uri);
          return true;
        }),
      ],
    );
  }

  final FakeCatalogue catalogue;
  final List<int> addedToCart = [];
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
