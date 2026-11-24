import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wegodeliva_auth/wegodeliva_auth.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import 'l10n/generated/app_localizations.dart';
import 'router.dart';
import 'settings/theme_mode.dart';
import 'update/update_gate.dart';

export 'router.dart' show routerProvider;

class CustomerApp extends ConsumerWidget {
  const CustomerApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return MaterialApp.router(
      onGenerateTitle: (context) => AppLocalizations.of(context).appTitle,
      theme: WgdTheme.light(),
      darkTheme: WgdTheme.dark(),
      themeMode: ref.watch(themeModeProvider),
      routerConfig: ref.watch(routerProvider),
      builder: (context, child) =>
          UpdateGate(child: child ?? const SizedBox.shrink()),
      localizationsDelegates: const [
        ...AppLocalizations.localizationsDelegates,
        AuthLocalizations.delegate,
      ],
      supportedLocales: AppLocalizations.supportedLocales,
    );
  }
}
