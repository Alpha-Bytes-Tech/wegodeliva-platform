import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:customer_app/app.dart';
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
  Future<OtpTiming> sendOtp(String email) async => const OtpTiming(expiresIn: 600, resendIn: 60);

  @override
  Future<AuthSession> verifyOtp(String email, String code, {String? deviceName}) =>
      throw UnimplementedError();
}

Widget appWith(TokenStorage storage, AuthRepository repository) => ProviderScope(
  overrides: [
    appConfigProvider.overrideWithValue(
      AppConfig.forFlavor(flavor: Flavor.dev, app: ClientApp.customer),
    ),
    tokenStorageProvider.overrideWithValue(storage),
    authRepositoryProvider.overrideWithValue(repository),
    loggerProvider.overrideWithValue(AppLogger(enabled: false)),
  ],
  child: const CustomerApp(),
);

void main() {
  testWidgets('goes from splash to the email login when signed out', (tester) async {
    await tester.pumpWidget(appWith(InMemoryTokenStorage(), FakeAuthRepository()));
    await tester.pumpAndSettle();

    expect(find.text('Sign in'), findsOneWidget);
    expect(find.byType(TextFormField), findsOneWidget);
  });

  testWidgets('opens the code screen after requesting a code', (tester) async {
    await tester.pumpWidget(appWith(InMemoryTokenStorage(), FakeAuthRepository()));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextFormField), 'ada@example.com');
    await tester.tap(find.text('Continue'));
    await tester.pumpAndSettle();

    expect(find.text('Enter your code'), findsOneWidget);
    await tester.tap(find.text('Use a different email'));
    await tester.pumpAndSettle();
    expect(find.text('Sign in'), findsOneWidget);
  });

  testWidgets('restores a stored session to the home screen and signs out', (tester) async {
    final repository = FakeAuthRepository();
    final storage = InMemoryTokenStorage('1|token');
    await tester.pumpWidget(appWith(storage, repository));
    await tester.pumpAndSettle();

    expect(find.text('Hello, Ada Obi'), findsOneWidget);
    expect(find.text('WeGoDeliva'), findsOneWidget);

    await tester.tap(find.byTooltip('Sign out'));
    await tester.pumpAndSettle();

    expect(find.text('Sign in'), findsOneWidget);
    expect(repository.logouts, 1);
    expect(await storage.read(), isNull);
  });
}
