import 'dart:io' show Platform;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import '../l10n/generated/app_localizations.dart';

/// Opens a link outside the app (store page, WhatsApp, phone). Replaced in tests.
final linkOpenerProvider = Provider<Future<bool> Function(Uri)>(
  (ref) =>
      (uri) => launchUrl(uri, mode: LaunchMode.externalApplication),
);

/// Blocks the app with "Update required" when the installed version is below the minimum
/// the server asks for (spec 09 §9.1 "Splash"). Settings that fail to load never block.
class UpdateGate extends ConsumerWidget {
  const UpdateGate({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final settings = ref.watch(appSettingsProvider).value;
    final installed = ref.watch(appConfigProvider).appVersion;
    if (settings == null || !settings.mustUpdate(installed)) return child;
    return UpdateRequiredScreen(storeUrl: settings.storeUrls[_platformKey()]);
  }

  static String _platformKey() {
    try {
      return Platform.isIOS ? 'ios' : 'android';
    } on Object {
      return 'android';
    }
  }
}

class UpdateRequiredScreen extends ConsumerWidget {
  const UpdateRequiredScreen({super.key, this.storeUrl});

  final String? storeUrl;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final url = storeUrl;
    return Scaffold(
      body: SafeArea(
        child: WgdStateView.empty(
          icon: Icons.system_update,
          title: l10n.updateRequiredTitle,
          message: l10n.updateRequiredMessage,
          actionLabel: url == null ? null : l10n.updateNow,
          onAction: url == null
              ? null
              : () => ref.read(linkOpenerProvider)(Uri.parse(url)),
        ),
      ),
    );
  }
}
