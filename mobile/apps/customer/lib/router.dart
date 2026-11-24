import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:wegodeliva_auth/wegodeliva_auth.dart';

import 'home/home_screen.dart';
import 'l10n/generated/app_localizations.dart';
import 'links/link_placeholder.dart';
import 'profile/page_screen.dart';
import 'profile/profile_screen.dart';
import 'shell/app_shell.dart';
import 'shell/tab_placeholders.dart';

/// Customer app routes (Phase 3 plan, 3.2). Tabs live in a shell with one stack each. Links
/// on wegodeliva.com open the same paths (Android App Links, iOS Universal Links):
/// `/r/{code}` referral, `/v/{slug}` store, `/p/{id}` product, `/o/{code}` order.
abstract final class CustomerRoutes {
  static const search = '/search';
  static const orders = '/orders';
  static const wallet = '/wallet';
  static const profile = '/profile';
  static String page(String slug) => '/pages/$slug';
}

final routerProvider = Provider<GoRouter>(
  (ref) => createAuthRouter(
    ref,
    allowSignUp: true,
    publicPrefixes: const ['/pages/', '/r/'],
    onOpenTerms: (context) => context.push(CustomerRoutes.page('terms')),
    homeRoute: StatefulShellRoute.indexedStack(
      builder: (context, state, shell) => AppShell(shell: shell),
      branches: [
        StatefulShellBranch(
          routes: [
            GoRoute(
              path: AuthRoutes.home,
              builder: (_, _) => const HomeScreen(),
            ),
          ],
        ),
        StatefulShellBranch(
          routes: [
            GoRoute(
              path: CustomerRoutes.search,
              builder: (context, _) {
                final l10n = AppLocalizations.of(context);
                return TabPlaceholder(
                  title: l10n.tabSearch,
                  icon: Icons.search,
                  message: l10n.searchComingMessage,
                );
              },
            ),
          ],
        ),
        StatefulShellBranch(
          routes: [
            GoRoute(
              path: CustomerRoutes.orders,
              builder: (context, _) {
                final l10n = AppLocalizations.of(context);
                return TabPlaceholder(
                  title: l10n.tabOrders,
                  icon: Icons.receipt_long_outlined,
                  message: l10n.ordersComingMessage,
                );
              },
            ),
          ],
        ),
        StatefulShellBranch(
          routes: [
            GoRoute(
              path: CustomerRoutes.wallet,
              builder: (context, _) {
                final l10n = AppLocalizations.of(context);
                return TabPlaceholder(
                  title: l10n.tabWallet,
                  icon: Icons.account_balance_wallet_outlined,
                  message: l10n.walletComingMessage,
                );
              },
            ),
          ],
        ),
        StatefulShellBranch(
          routes: [
            GoRoute(
              path: CustomerRoutes.profile,
              builder: (_, _) => const ProfileScreen(),
            ),
          ],
        ),
      ],
    ),
    extraRoutes: [
      // Pages are public so sign-up can show the terms.
      GoRoute(
        path: '/pages/:slug',
        builder: (_, state) =>
            ContentPageScreen(slug: state.pathParameters['slug']!),
      ),
      // A referral link keeps the code for sign-up, then shows sign-in (or home if signed in).
      GoRoute(
        path: '/r/:code',
        redirect: (_, state) {
          ref
              .read(pendingReferralProvider.notifier)
              .set(state.pathParameters['code']);
          return AuthRoutes.home;
        },
      ),
      GoRoute(
        path: '/v/:slug',
        builder: (context, _) =>
            LinkPlaceholder(title: AppLocalizations.of(context).storeTitle),
      ),
      GoRoute(
        path: '/p/:id',
        builder: (context, _) =>
            LinkPlaceholder(title: AppLocalizations.of(context).productTitle),
      ),
      GoRoute(
        path: '/o/:code',
        builder: (context, state) =>
            LinkPlaceholder(title: state.pathParameters['code']!),
      ),
    ],
  ),
);
