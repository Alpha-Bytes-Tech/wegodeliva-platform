import 'package:flutter_test/flutter_test.dart';
import 'package:wegodeliva_auth/wegodeliva_auth.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

import 'helpers.dart';

void main() {
  test('waits on the splash screen while the session is unknown', () {
    expect(
      authRedirect(const AuthUnknown(), AuthRoutes.home),
      AuthRoutes.splash,
    );
    expect(authRedirect(const AuthUnknown(), AuthRoutes.splash), isNull);
  });

  test(
    'keeps an offline session on the splash screen instead of the login',
    () {
      const offline = SessionOffline(
        attempt: 1,
        nextRetryIn: Duration(seconds: 2),
      );
      expect(authRedirect(offline, AuthRoutes.home), AuthRoutes.splash);
      expect(authRedirect(offline, AuthRoutes.login), AuthRoutes.splash);
      expect(authRedirect(offline, AuthRoutes.splash), isNull);
    },
  );

  test(
    'sends signed-out users to login, but lets them reach the code screen',
    () {
      expect(
        authRedirect(const SignedOut(), AuthRoutes.home),
        AuthRoutes.login,
      );
      expect(
        authRedirect(const SignedOut(), AuthRoutes.splash),
        AuthRoutes.login,
      );
      expect(authRedirect(const SignedOut(), AuthRoutes.login), isNull);
      expect(authRedirect(const SignedOut(), AuthRoutes.otp), isNull);
      expect(
        authRedirect(const SignedOut(), AuthRoutes.otp, hasOtpArgs: false),
        AuthRoutes.login,
      );
    },
  );

  test('keeps signed-in users out of the login flow', () {
    const signedIn = SignedIn(testUser);
    expect(authRedirect(signedIn, AuthRoutes.login), AuthRoutes.home);
    expect(authRedirect(signedIn, AuthRoutes.otp), AuthRoutes.home);
    expect(authRedirect(signedIn, AuthRoutes.splash), AuthRoutes.home);
    expect(authRedirect(signedIn, AuthRoutes.home), isNull);
  });

  test('keeps a deep link through splash and sign-in, then opens it', () {
    expect(
      authRedirect(
        const AuthUnknown(),
        '/v/mama-put',
        fullLocation: '/v/mama-put?tab=menu',
      ),
      '/splash?from=%2Fv%2Fmama-put%3Ftab%3Dmenu',
    );
    expect(
      authRedirect(const SignedOut(), AuthRoutes.splash, from: '/v/mama-put'),
      '/login?from=%2Fv%2Fmama-put',
    );
    expect(
      authRedirect(const SignedOut(), AuthRoutes.login, from: '/v/mama-put'),
      isNull,
    );
    expect(
      authRedirect(const SignedOut(), AuthRoutes.signUp, from: '/v/mama-put'),
      isNull,
    );
    expect(
      authRedirect(
        const SignedOut(),
        AuthRoutes.otp,
        hasOtpArgs: false,
        from: '/o/ABC123',
      ),
      '/login?from=%2Fo%2FABC123',
    );
    expect(
      authRedirect(const SignedIn(testUser), AuthRoutes.otp, from: '/o/ABC123'),
      '/o/ABC123',
    );
    expect(
      authRedirect(const SignedIn(testUser), AuthRoutes.splash, from: '/v/x'),
      '/v/x',
    );
  });

  test('never follows a return address outside the app', () {
    const signedIn = SignedIn(testUser);
    for (final bad in [
      '//evil.example/x',
      'https://evil.example',
      '/',
      '/login/code',
      '/splash',
    ]) {
      expect(
        authRedirect(signedIn, AuthRoutes.login, from: bad),
        AuthRoutes.home,
        reason: bad,
      );
    }
    expect(authRedirect(const SignedOut(), AuthRoutes.home), AuthRoutes.login);
  });

  test('lets anyone open public pages', () {
    expect(authRedirect(const SignedOut(), '/pages/terms', isPublic: true), isNull);
    expect(authRedirect(const AuthUnknown(), '/r/ADA12', isPublic: true), isNull);
  });
}
