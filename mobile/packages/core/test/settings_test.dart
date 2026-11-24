import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

import 'support/fake_adapter.dart';

const settingsJson = {
  'data': {
    'app': {
      'min_version': '1.2.0',
      'latest_version': '1.4.0',
      'store_urls': {'android': 'https://play.example/app', 'ios': null},
    },
    'features': {'parcels': true, 'referrals': false},
    'payment_methods': {'wallet': true, 'paystack': false},
    'support': {'whatsapp': '+2348012345678', 'email': 'help@example.com'},
    'support_categories': ['order', 'other'],
    'pages': [
      {'slug': 'terms', 'title': 'Terms'},
    ],
    'onboarding': [
      {
        'title': 'Order anything',
        'subtitle': 'Food to parcels',
        'image_url': null,
        'link': null,
      },
    ],
  },
};

void main() {
  final config = AppConfig.forFlavor(
    flavor: Flavor.staging,
    app: ClientApp.customer,
    appVersion: '1.3.0',
  );

  SettingsRepository repositoryWith(FakeAdapter adapter, KeyValueStore store) =>
      SettingsRepository(
        ApiClient(
          config: config,
          tokenStorage: InMemoryTokenStorage(),
          dio: Dio()..httpClientAdapter = adapter,
          logger: AppLogger(enabled: false),
        ),
        store,
        ClientApp.customer,
      );

  test('compares versions numerically, ignoring suffixes', () {
    expect(compareVersions('1.2.10', '1.2.9'), 1);
    expect(compareVersions('1.2', '1.2.0'), 0);
    expect(compareVersions('1.0.0-beta+3', '1.0.0'), 0);
    expect(compareVersions('0.9', '1.0'), -1);
    expect(compareVersions('x.1', '0.1'), 0);
  });

  test(
    'reads settings, keeps a copy, and says when an update is needed',
    () async {
      final adapter = FakeAdapter((_) => FakeAdapter.json(200, settingsJson));
      final store = InMemoryKeyValueStore();

      final settings = await repositoryWith(adapter, store).load();

      expect(adapter.requests.single.uri.path, endsWith('/app/settings'));
      expect(adapter.requests.single.uri.queryParameters['app'], 'customer');
      expect(settings.feature('parcels'), isTrue);
      expect(settings.feature('referrals'), isFalse);
      expect(settings.feature('unknown'), isFalse);
      expect(settings.paymentMethods['wallet'], isTrue);
      expect(settings.storeUrls['android'], 'https://play.example/app');
      expect(settings.support.whatsapp, '+2348012345678');
      expect(settings.supportCategories, ['order', 'other']);
      expect(settings.pages.single.title, 'Terms');
      expect(settings.onboarding.single.subtitle, 'Food to parcels');
      expect(settings.mustUpdate('1.1.9'), isTrue);
      expect(settings.mustUpdate('1.2.0'), isFalse);
      expect(settings.canUpdate('1.3.0'), isTrue);
      expect(settings.canUpdate('1.4.0'), isFalse);
      expect(
        store.values[SettingsRepository.cacheKey],
        contains('min_version'),
      );
    },
  );

  test(
    'falls back to the last copy when offline, and fails without one',
    () async {
      final offline = FakeAdapter(
        (o) => throw DioException(
          requestOptions: o,
          error: const SocketException('offline'),
          type: DioExceptionType.connectionError,
        ),
      );

      await expectLater(
        repositoryWith(offline, InMemoryKeyValueStore()).load(),
        throwsA(isA<ApiException>()),
      );

      final store = InMemoryKeyValueStore();
      await repositoryWith(
        FakeAdapter((_) => FakeAdapter.json(200, settingsJson)),
        store,
      ).load();
      final cached = await repositoryWith(offline, store).load();
      expect(cached.minVersion, '1.2.0');
    },
  );

  test(
    'does not hide server errors behind the cache, and ignores a broken cache',
    () async {
      final store = InMemoryKeyValueStore({
        SettingsRepository.cacheKey: 'not json',
      });
      final failing = FakeAdapter(
        (_) => FakeAdapter.json(500, {
          'error': {'code': 'server_error', 'message': 'x'},
        }),
      );

      await expectLater(
        repositoryWith(failing, store).load(),
        throwsA(isA<ApiException>()),
      );
      expect(await repositoryWith(failing, store).cachedSettings(), isNull);
      expect(AppSettings.fromJson(const {}).minVersion, '0.0.0');
    },
  );

  test('stores preferences in memory for tests', () async {
    final store = InMemoryKeyValueStore();
    await store.write('k', 'v');
    expect(await store.read('k'), 'v');
    await store.remove('k');
    expect(await store.read('k'), isNull);
  });

  test('returns the whole body for paginated lists, and errors as ApiException', () async {
    final ok = FakeAdapter((_) => FakeAdapter.json(200, {'data': [1], 'meta': {'next_cursor': 'abc'}}));
    final client = ApiClient(config: config, tokenStorage: InMemoryTokenStorage(), dio: Dio()..httpClientAdapter = ok, logger: AppLogger(enabled: false));
    expect((await client.getEnvelope('/x'))['meta'], {'next_cursor': 'abc'});

    final notFound = FakeAdapter((_) => FakeAdapter.json(404, {'error': {'code': 'not_found', 'message': 'Nope'}}));
    final failing = ApiClient(config: config, tokenStorage: InMemoryTokenStorage(), dio: Dio()..httpClientAdapter = notFound, logger: AppLogger(enabled: false));
    await expectLater(failing.getEnvelope('/x'), throwsA(isA<ApiException>().having((e) => e.code, 'code', 'not_found')));

    final odd = FakeAdapter((_) => FakeAdapter.json(200, [1, 2]));
    final weird = ApiClient(config: config, tokenStorage: InMemoryTokenStorage(), dio: Dio()..httpClientAdapter = odd, logger: AppLogger(enabled: false));
    await expectLater(weird.getEnvelope('/x'), throwsA(isA<ApiException>()));
  });
}
