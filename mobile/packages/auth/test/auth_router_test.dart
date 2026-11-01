import 'package:flutter_test/flutter_test.dart';
import 'package:wegodeliva_auth/wegodeliva_auth.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

import 'helpers.dart';

void main() {
  test('waits on the splash screen while the session is unknown', () {
    expect(authRedirect(const AuthUnknown(), AuthRoutes.home), AuthRoutes.splash);
    expect(authRedirect(const AuthUnknown(), AuthRoutes.splash), isNull);
  });

  test('sends signed-out users to login, but lets them reach the code screen', () {
    expect(authRedirect(const SignedOut(), AuthRoutes.home), AuthRoutes.login);
    expect(authRedirect(const SignedOut(), AuthRoutes.splash), AuthRoutes.login);
    expect(authRedirect(const SignedOut(), AuthRoutes.login), isNull);
    expect(authRedirect(const SignedOut(), AuthRoutes.otp), isNull);
    expect(authRedirect(const SignedOut(), AuthRoutes.otp, hasOtpArgs: false), AuthRoutes.login);
  });

  test('keeps signed-in users out of the login flow', () {
    const signedIn = SignedIn(testUser);
    expect(authRedirect(signedIn, AuthRoutes.login), AuthRoutes.home);
    expect(authRedirect(signedIn, AuthRoutes.otp), AuthRoutes.home);
    expect(authRedirect(signedIn, AuthRoutes.splash), AuthRoutes.home);
    expect(authRedirect(signedIn, AuthRoutes.home), isNull);
  });
}
