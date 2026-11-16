import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:wegodeliva_auth/wegodeliva_auth.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import 'home_screen.dart';
import 'l10n/generated/app_localizations.dart';

final routerProvider = Provider<GoRouter>(
  (ref) => createAuthRouter(
    ref,
    appSubtitle: 'Rider',
    homeBuilder: (context) => const HomeScreen(),
  ),
);

class RiderApp extends ConsumerWidget {
  const RiderApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return MaterialApp.router(
      onGenerateTitle: (context) => AppLocalizations.of(context).appTitle,
      theme: WgdTheme.light(),
      darkTheme: WgdTheme.dark(),
      routerConfig: ref.watch(routerProvider),
      localizationsDelegates: const [
        ...AppLocalizations.localizationsDelegates,
        AuthLocalizations.delegate,
      ],
      supportedLocales: AppLocalizations.supportedLocales,
    );
  }
}
