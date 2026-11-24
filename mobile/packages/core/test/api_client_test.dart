import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

import 'support/fake_adapter.dart';

void main() {
  final config = AppConfig.forFlavor(
    flavor: Flavor.staging,
    app: ClientApp.rider,
    appVersion: '1.2.3',
  );

  ApiClient clientWith(
    FakeAdapter adapter, {
    TokenStorage? storage,
    SessionRejectedCallback? onSessionRejected,
  }) {
    final dio = Dio()..httpClientAdapter = adapter;
    return ApiClient(
      config: config,
      tokenStorage: storage ?? InMemoryTokenStorage(),
      logger: AppLogger(enabled: false),
      dio: dio,
      onSessionRejected: onSessionRejected,
    );
  }

  Map<String, Object?> errorBody(String code) => {
    'error': {'code': code, 'message': code, 'fields': <String, Object?>{}},
  };

  group('rejected sessions', () {
    test(
      'a 401 on an authenticated request clears the token and signs out',
      () async {
        final storage = InMemoryTokenStorage('1|abc');
        var rejected = 0;
        final client = clientWith(
          FakeAdapter(
            (_) => FakeAdapter.json(401, errorBody('unauthenticated')),
          ),
          storage: storage,
          onSessionRejected: () async => rejected++,
        );

        await expectLater(
          client.getJson('/devices'),
          throwsA(
            isA<ApiException>().having((e) => e.statusCode, 'status', 401),
          ),
        );

        expect(rejected, 1);
        expect(await storage.read(), isNull);
      },
    );

    test('a 403 account_suspended does the same', () async {
      final storage = InMemoryTokenStorage('1|abc');
      var rejected = 0;
      final client = clientWith(
        FakeAdapter(
          (_) => FakeAdapter.json(403, errorBody('account_suspended')),
        ),
        storage: storage,
        onSessionRejected: () async => rejected++,
      );

      await expectLater(
        client.getJson('/auth/me'),
        throwsA(isA<ApiException>()),
      );

      expect(rejected, 1);
      expect(await storage.read(), isNull);
    });

    test('other 403s and errors keep the session', () async {
      for (final (status, code) in [
        (403, 'role_not_allowed'),
        (422, 'otp_invalid'),
        (500, 'x'),
      ]) {
        final storage = InMemoryTokenStorage('1|abc');
        var rejected = 0;
        final client = clientWith(
          FakeAdapter((_) => FakeAdapter.json(status, errorBody(code))),
          storage: storage,
          onSessionRejected: () async => rejected++,
        );

        await expectLater(client.getJson('/x'), throwsA(isA<ApiException>()));

        expect(rejected, 0, reason: '$status $code');
        expect(await storage.read(), '1|abc');
      }
    });

    test(
      'a 401 without a token (wrong password) is not a session rejection',
      () async {
        var rejected = 0;
        final client = clientWith(
          FakeAdapter(
            (_) => FakeAdapter.json(401, errorBody('invalid_credentials')),
          ),
          onSessionRejected: () async => rejected++,
        );

        await expectLater(
          client.postJson('/auth/login', {}),
          throwsA(isA<ApiException>()),
        );

        expect(rejected, 0);
      },
    );

    test('the provider wiring moves the AuthController to SignedOut', () async {
      final storage = InMemoryTokenStorage('1|abc');
      final container = ProviderContainer.test(
        overrides: [
          appConfigProvider.overrideWithValue(config),
          tokenStorageProvider.overrideWithValue(storage),
          loggerProvider.overrideWithValue(AppLogger(enabled: false)),
        ],
      );
      final client = container.read(apiClientProvider);
      client.dio.httpClientAdapter = FakeAdapter(
        (o) => o.path == '/auth/me'
            ? FakeAdapter.json(200, {
                'data': {
                  'uuid': 'u-1',
                  'name': 'Ada',
                  'email': 'a@x.test',
                  'phone': '+2348031234567',
                  'status': 'active',
                },
              })
            : FakeAdapter.json(401, errorBody('unauthenticated')),
      );
      await container.read(authControllerProvider.notifier).restore();
      expect(container.read(authControllerProvider), isA<SignedIn>());

      await expectLater(
        client.getJson('/devices'),
        throwsA(isA<ApiException>()),
      );

      expect(container.read(authControllerProvider), isA<SignedOut>());
      expect(await storage.read(), isNull);
    });
  });

  group('AppConfig.load', () {
    test('sends the installed app version, not a hard-coded one', () async {
      PackageInfo.setMockInitialValues(
        appName: 'WeGoDeliva Rider',
        packageName: 'com.wegodeliva.riderapp',
        version: '2.4.1',
        buildNumber: '57',
        buildSignature: '',
      );
      final loaded = await AppConfig.load(
        flavor: Flavor.staging,
        app: ClientApp.rider,
      );
      final adapter = FakeAdapter(
        (_) => FakeAdapter.json(200, {'data': <String, Object?>{}}),
      );
      final dio = Dio()..httpClientAdapter = adapter;
      final client = ApiClient(
        config: loaded,
        tokenStorage: InMemoryTokenStorage(),
        logger: AppLogger(enabled: false),
        dio: dio,
      );

      await client.getJson('/health');

      expect(loaded.appVersion, '2.4.1');
      expect(adapter.requests.single.headers['X-App-Version'], '2.4.1');
    });
  });

  test(
    'uses the flavour base URL and sends app headers and the stored token',
    () async {
      final adapter = FakeAdapter(
        (_) => FakeAdapter.json(200, {'data': <String, Object?>{}}),
      );
      final client = clientWith(
        adapter,
        storage: InMemoryTokenStorage('1|abc'),
      );

      await client.getJson('/auth/me');

      final request = adapter.requests.single;
      expect(
        request.uri.toString(),
        'https://wegodeliva-platform-eu-production-q1ab72.laravel.cloud/api/v1/auth/me',
      );
      expect(request.headers['Authorization'], 'Bearer 1|abc');
      expect(request.headers['X-App-Version'], '1.2.3');
      expect(request.headers['X-App'], 'rider');
    },
  );

  test('sends no Authorization header when signed out', () async {
    final adapter = FakeAdapter(
      (_) => FakeAdapter.json(200, {'data': <String, Object?>{}}),
    );

    await clientWith(adapter).getJson('/health');

    expect(
      adapter.requests.single.headers.containsKey('Authorization'),
      isFalse,
    );
  });

  test('maps the API error envelope to ApiException', () async {
    final adapter = FakeAdapter(
      (_) => FakeAdapter.json(422, {
        'error': {
          'code': 'otp_invalid',
          'message': 'That code is not correct.',
          'fields': <String, Object?>{},
          'meta': {'attempts_remaining': 3},
        },
      }),
    );

    await expectLater(
      clientWith(adapter).postJson('/auth/otp/verify', {}),
      throwsA(
        isA<ApiException>()
            .having((e) => e.statusCode, 'status', 422)
            .having((e) => e.code, 'code', 'otp_invalid')
            .having((e) => e.error.meta['attempts_remaining'], 'meta', 3),
      ),
    );
  });

  test('maps validation field errors', () async {
    final adapter = FakeAdapter(
      (_) => FakeAdapter.json(400, {
        'error': {
          'code': 'validation_failed',
          'message': 'Some fields need your attention.',
          'fields': {
            'email': ['The email field is required.'],
          },
        },
      }),
    );

    try {
      await clientWith(adapter).postJson('/auth/register', {});
      fail('expected ApiException');
    } on ApiException catch (e) {
      expect(e.error.fields['email'], ['The email field is required.']);
    }
  });

  test('falls back to a generic error for unexpected bodies', () async {
    final adapter = FakeAdapter(
      (_) => ResponseBody.fromString('<html>Bad gateway</html>', 502),
    );

    await expectLater(
      clientWith(adapter).getJson('/auth/me'),
      throwsA(
        isA<ApiException>().having((e) => e.code, 'code', 'server_error'),
      ),
    );
  });

  test('reports network failures as network_error', () {
    final exception = ApiClient.toApiException(
      DioException.connectionError(
        requestOptions: RequestOptions(path: '/x'),
        reason: 'no route',
      ),
    );
    expect(exception.code, 'network_error');
    expect(exception.statusCode, isNull);
  });

  test('rejects a response without a data object', () async {
    final adapter = FakeAdapter(
      (_) => FakeAdapter.json(200, {'unexpected': true}),
    );

    await expectLater(
      clientWith(adapter).getJson('/auth/me'),
      throwsA(isA<ApiException>()),
    );
  });

  group('AppConfig', () {
    test('only allows plain HTTP for dev builds', () {
      expect(
        () => AppConfig.forFlavor(
          flavor: Flavor.prod,
          app: ClientApp.customer,
          appVersion: '1.0.0',
          apiBaseUrlOverride: 'http://api.example.com',
        ),
        throwsArgumentError,
      );
      final dev = AppConfig.forFlavor(
        flavor: Flavor.dev,
        app: ClientApp.customer,
        appVersion: '1.0.0',
      );
      expect(dev.apiBaseUrl, startsWith('http://'));
      expect(Flavor.prod.isProduction, isTrue);
    });
  });
}
