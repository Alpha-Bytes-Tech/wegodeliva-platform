import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

import 'support/fake_adapter.dart';

void main() {
  final config = AppConfig.forFlavor(
    flavor: Flavor.staging,
    app: ClientApp.rider,
    appVersion: '1.2.3',
  );

  ApiClient clientWith(FakeAdapter adapter, {TokenStorage? storage}) {
    final dio = Dio()..httpClientAdapter = adapter;
    return ApiClient(
      config: config,
      tokenStorage: storage ?? InMemoryTokenStorage(),
      logger: AppLogger(enabled: false),
      dio: dio,
    );
  }

  test('uses the flavour base URL and sends app headers and the stored token', () async {
    final adapter = FakeAdapter((_) => FakeAdapter.json(200, {'data': <String, Object?>{}}));
    final client = clientWith(adapter, storage: InMemoryTokenStorage('1|abc'));

    await client.getJson('/auth/me');

    final request = adapter.requests.single;
    expect(request.uri.toString(), 'https://api-staging.wegodeliva.com/api/v1/auth/me');
    expect(request.headers['Authorization'], 'Bearer 1|abc');
    expect(request.headers['X-App-Version'], '1.2.3');
    expect(request.headers['X-App'], 'rider');
  });

  test('sends no Authorization header when signed out', () async {
    final adapter = FakeAdapter((_) => FakeAdapter.json(200, {'data': <String, Object?>{}}));

    await clientWith(adapter).getJson('/health');

    expect(adapter.requests.single.headers.containsKey('Authorization'), isFalse);
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
    final adapter = FakeAdapter((_) => ResponseBody.fromString('<html>Bad gateway</html>', 502));

    await expectLater(
      clientWith(adapter).getJson('/auth/me'),
      throwsA(isA<ApiException>().having((e) => e.code, 'code', 'server_error')),
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
    final adapter = FakeAdapter((_) => FakeAdapter.json(200, {'unexpected': true}));

    await expectLater(clientWith(adapter).getJson('/auth/me'), throwsA(isA<ApiException>()));
  });

  group('AppConfig', () {
    test('only allows plain HTTP for dev builds', () {
      expect(
        () => AppConfig.forFlavor(
          flavor: Flavor.prod,
          app: ClientApp.customer,
          apiBaseUrlOverride: 'http://api.example.com',
        ),
        throwsArgumentError,
      );
      final dev = AppConfig.forFlavor(flavor: Flavor.dev, app: ClientApp.customer);
      expect(dev.apiBaseUrl, startsWith('http://'));
      expect(Flavor.prod.isProduction, isTrue);
    });
  });
}
