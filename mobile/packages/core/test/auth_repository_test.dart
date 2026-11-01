import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

import 'support/fake_adapter.dart';

void main() {
  late FakeAdapter adapter;
  late AuthRepository repository;

  setUp(() {
    adapter = FakeAdapter((options) {
      switch (options.path) {
        case '/auth/otp/send':
          return FakeAdapter.json(202, {
            'data': {
              'otp': {'expires_in': 600, 'resend_in': 60},
            },
          });
        case '/auth/otp/verify':
          return FakeAdapter.json(200, {
            'data': {
              'token': '1|abc',
              'token_type': 'Bearer',
              'expires_at': '2027-01-01T00:00:00+00:00',
              'app': 'vendor',
              'user': {
                'uuid': 'u-1',
                'name': 'Ada',
                'email': 'ada@example.com',
                'email_verified': true,
                'phone': '+2348031234567',
                'phone_verified': false,
                'status': 'active',
                'roles': ['vendor_owner'],
              },
            },
          });
        default:
          return FakeAdapter.json(204, <String, Object?>{});
      }
    });
    final client = ApiClient(
      config: AppConfig.forFlavor(flavor: Flavor.dev, app: ClientApp.vendor),
      tokenStorage: InMemoryTokenStorage(),
      logger: AppLogger(enabled: false),
      dio: Dio()..httpClientAdapter = adapter,
    );
    repository = AuthRepository(client, ClientApp.vendor);
  });

  Map<String, Object?> body(int index) =>
      jsonDecode(jsonEncode(adapter.requests[index].data)) as Map<String, Object?>;

  test('sendOtp posts the email and app and returns the timing', () async {
    final timing = await repository.sendOtp('  ada@example.com ');

    expect(timing.resendIn, 60);
    expect(timing.expiresIn, 600);
    expect(body(0), {'email': 'ada@example.com', 'app': 'vendor', 'purpose': 'login'});
  });

  test('verifyOtp returns a session for this app', () async {
    final session = await repository.verifyOtp('ada@example.com', '123456', deviceName: 'Pixel');

    expect(session.token, '1|abc');
    expect(session.user.roles, ['vendor_owner']);
    expect(session.expiresAt.isUtc, isTrue);
    expect(body(0)['device_name'], 'Pixel');
    expect(repository.app, ClientApp.vendor);
  });

  test('logout calls the API', () async {
    await repository.logout();
    expect(adapter.requests.single.path, '/auth/logout');
  });
}
