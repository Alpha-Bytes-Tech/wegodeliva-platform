import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

import 'email_login_screen.dart';
import 'otp_screen.dart';
import 'sign_up_screen.dart';
import 'splash_screen.dart';

/// Route paths shared by the three apps.
abstract final class AuthRoutes {
  static const splash = '/splash';
  static const login = '/login';
  static const otp = '/login/code';
  static const signUp = '/login/sign-up';
  static const home = '/';
}

/// What the OTP route needs from the login or sign-up route.
class OtpRouteArgs {
  const OtpRouteArgs(this.email, this.timing);

  final String email;
  final OtpTiming timing;
}

/// Builds the app router: splash → login (or sign-up) → code → home, guarded by
/// [authControllerProvider]. A deep link opened while signed out is kept in `?from=` and
/// opened once the user has signed in.
///
/// [homeRoute] replaces the default single home screen (e.g. with a bottom-navigation shell);
/// [homeBuilder] is used otherwise. [onOpenTerms] adds a "Read the terms" link to sign-up.
GoRouter createAuthRouter(
  Ref ref, {
  WidgetBuilder? homeBuilder,
  RouteBase? homeRoute,
  String? appSubtitle,
  bool allowSignUp = false,
  void Function(BuildContext context)? onOpenTerms,
  List<RouteBase> extraRoutes = const [],
  List<String> publicPrefixes = const [],
}) {
  assert(
    homeBuilder != null || homeRoute != null,
    'Give a homeBuilder or a homeRoute',
  );
  final refresh = ValueNotifier<int>(0);
  ref.listen<AuthState>(authControllerProvider, (_, _) => refresh.value++);
  ref.onDispose(refresh.dispose);

  String withFrom(String path, GoRouterState state) {
    final from = state.uri.queryParameters['from'];
    return from == null
        ? path
        : Uri(path: path, queryParameters: {'from': from}).toString();
  }

  void toCode(
    BuildContext context,
    GoRouterState state,
    String email,
    OtpTiming timing,
  ) => context.push(
    withFrom(AuthRoutes.otp, state),
    extra: OtpRouteArgs(email, timing),
  );

  return GoRouter(
    initialLocation: AuthRoutes.splash,
    refreshListenable: refresh,
    redirect: (context, state) => authRedirect(
      ref.read(authControllerProvider),
      state.matchedLocation,
      hasOtpArgs: state.extra is OtpRouteArgs,
      from: state.uri.queryParameters['from'],
      fullLocation: state.uri.toString(),
      isPublic: publicPrefixes.any(state.matchedLocation.startsWith),
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
          onCodeSent: (email, timing) => toCode(context, state, email, timing),
          onSignUp: allowSignUp
              ? () => context.push(withFrom(AuthRoutes.signUp, state))
              : null,
        ),
        routes: [
          GoRoute(
            path: 'code',
            builder: (context, state) {
              final args = state.extra! as OtpRouteArgs;
              return OtpScreen(
                email: args.email,
                initialTiming: args.timing,
                onChangeEmail: () =>
                    context.go(withFrom(AuthRoutes.login, state)),
              );
            },
          ),
          if (allowSignUp)
            GoRoute(
              path: 'sign-up',
              builder: (context, state) => SignUpScreen(
                onCodeSent: (email, timing) =>
                    toCode(context, state, email, timing),
                onSignIn: () => context.go(withFrom(AuthRoutes.login, state)),
                onOpenTerms: onOpenTerms == null
                    ? null
                    : () => onOpenTerms(context),
              ),
            ),
        ],
      ),
      homeRoute ??
          GoRoute(
            path: AuthRoutes.home,
            builder: (context, state) => homeBuilder!(context),
          ),
      ...extraRoutes,
    ],
  );
}

/// The guard, separated for unit tests. [location] is the matched path; [fullLocation] is
/// the whole URI (with query) to come back to after signing in; [from] is a location carried
/// through splash and login. Public pages (legal pages, referral links) open for anyone.
String? authRedirect(
  AuthState auth,
  String location, {
  bool hasOtpArgs = true,
  String? from,
  String? fullLocation,
  bool isPublic = false,
}) {
  if (isPublic) return null;
  final inLogin = location.startsWith(AuthRoutes.login);
  final atSplash = location == AuthRoutes.splash;
  // Where to return after sign-in: a deep link, never the auth screens or plain home.
  final keep = (inLogin || atSplash) ? from : (fullLocation ?? location);
  final returnTo = _safeReturn(keep);
  String withFrom(String path) => returnTo == null
      ? path
      : Uri(path: path, queryParameters: {'from': returnTo}).toString();

  return switch (auth) {
    // Token kept but not confirmed yet: wait on the splash screen, which offers a retry.
    AuthUnknown() ||
    SessionOffline() => atSplash ? null : withFrom(AuthRoutes.splash),
    SignedOut() when location == AuthRoutes.otp && !hasOtpArgs => withFrom(
      AuthRoutes.login,
    ),
    SignedOut() => inLogin ? null : withFrom(AuthRoutes.login),
    SignedIn() => (inLogin || atSplash) ? (returnTo ?? AuthRoutes.home) : null,
  };
}

/// Only in-app paths are followed: `/v/x` yes; `//evil.com`, `https://…` or the home path no.
String? _safeReturn(String? location) {
  if (location == null ||
      !location.startsWith('/') ||
      location.startsWith('//')) {
    return null;
  }
  final path = Uri.tryParse(location)?.path;
  if (path == null ||
      path == AuthRoutes.home ||
      path == AuthRoutes.splash ||
      path.startsWith(AuthRoutes.login)) {
    return null;
  }
  return location;
}
