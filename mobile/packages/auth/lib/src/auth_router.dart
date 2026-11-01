import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

import 'email_login_screen.dart';
import 'otp_screen.dart';
import 'splash_screen.dart';

/// Route paths shared by the three apps.
abstract final class AuthRoutes {
  static const splash = '/splash';
  static const login = '/login';
  static const otp = '/login/code';
  static const home = '/';
}

/// What the OTP route needs from the login route.
class OtpRouteArgs {
  const OtpRouteArgs(this.email, this.timing);

  final String email;
  final OtpTiming timing;
}

/// Builds the app router: splash → login → code → home, guarded by [authControllerProvider].
GoRouter createAuthRouter(
  Ref ref, {
  required WidgetBuilder homeBuilder,
  String? appSubtitle,
  List<RouteBase> extraRoutes = const [],
}) {
  final refresh = ValueNotifier<int>(0);
  ref.listen<AuthState>(authControllerProvider, (_, _) => refresh.value++);
  ref.onDispose(refresh.dispose);

  return GoRouter(
    initialLocation: AuthRoutes.splash,
    refreshListenable: refresh,
    redirect: (context, state) => authRedirect(
      ref.read(authControllerProvider),
      state.matchedLocation,
      hasOtpArgs: state.extra is OtpRouteArgs,
    ),
    routes: [
      GoRoute(
        path: AuthRoutes.splash,
        builder: (context, state) => SplashScreen(appSubtitle: appSubtitle),
      ),
      GoRoute(
        path: AuthRoutes.login,
        builder: (context, state) => EmailLoginScreen(
          appSubtitle: appSubtitle,
          onCodeSent: (email, timing) =>
              context.push(AuthRoutes.otp, extra: OtpRouteArgs(email, timing)),
        ),
        routes: [
          GoRoute(
            path: 'code',
            builder: (context, state) {
              final args = state.extra! as OtpRouteArgs;
              return OtpScreen(
                email: args.email,
                initialTiming: args.timing,
                onChangeEmail: () => context.go(AuthRoutes.login),
              );
            },
          ),
        ],
      ),
      GoRoute(path: AuthRoutes.home, builder: (context, state) => homeBuilder(context)),
      ...extraRoutes,
    ],
  );
}

/// The guard, separated for unit tests.
String? authRedirect(AuthState auth, String location, {bool hasOtpArgs = true}) {
  final inLogin = location.startsWith(AuthRoutes.login);
  return switch (auth) {
    AuthUnknown() => location == AuthRoutes.splash ? null : AuthRoutes.splash,
    SignedOut() when location == AuthRoutes.otp && !hasOtpArgs => AuthRoutes.login,
    SignedOut() => inLogin ? null : AuthRoutes.login,
    SignedIn() => (inLogin || location == AuthRoutes.splash) ? AuthRoutes.home : null,
  };
}
