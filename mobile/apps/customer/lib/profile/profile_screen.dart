import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import '../l10n/generated/app_localizations.dart';
import '../settings/theme_mode.dart';
import '../update/update_gate.dart';

/// Profile (spec 09 §9.7). Account details, appearance, help and legal pages, sign out.
/// Editing the profile, addresses, referrals and support tickets come in 3.6.
class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);
    final user = auth is SignedIn ? auth.user : null;
    final settings = ref.watch(appSettingsProvider).value;
    final mode = ref.watch(themeModeProvider);
    final whatsapp = settings?.support.whatsapp;
    final installed = ref.watch(appConfigProvider).appVersion;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.tabProfile)),
      body: ListView(
        children: [
          if (user != null)
            ListTile(
              leading: CircleAvatar(
                child: Text(
                  user.name.isEmpty ? '?' : user.name[0].toUpperCase(),
                ),
              ),
              title: Text(user.name),
              subtitle: Text(user.email),
            ),
          const Divider(),
          Padding(
            padding: const EdgeInsets.fromLTRB(
              WgdSpacing.md,
              WgdSpacing.sm,
              WgdSpacing.md,
              0,
            ),
            child: Text(
              l10n.appearance,
              style: Theme.of(context).textTheme.titleSmall,
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(WgdSpacing.md),
            child: SegmentedButton<ThemeMode>(
              segments: [
                ButtonSegment(
                  value: ThemeMode.system,
                  label: Text(l10n.themeSystem),
                ),
                ButtonSegment(
                  value: ThemeMode.light,
                  label: Text(l10n.themeLight),
                ),
                ButtonSegment(
                  value: ThemeMode.dark,
                  label: Text(l10n.themeDark),
                ),
              ],
              selected: {mode},
              onSelectionChanged: (s) =>
                  ref.read(themeModeProvider.notifier).set(s.first),
            ),
          ),
          const Divider(),
          if (whatsapp != null && whatsapp.isNotEmpty)
            ListTile(
              leading: const Icon(Icons.support_agent),
              title: Text(l10n.chatOnWhatsApp),
              onTap: () => ref.read(linkOpenerProvider)(
                Uri.parse(
                  'https://wa.me/${whatsapp.replaceAll(RegExp(r'\D'), '')}',
                ),
              ),
            ),
          for (final page in settings?.pages ?? const <PageLink>[])
            ListTile(
              leading: const Icon(Icons.description_outlined),
              title: Text(page.title),
              onTap: () => context.push('/pages/${page.slug}'),
            ),
          if (settings != null && settings.canUpdate(installed))
            ListTile(
              leading: const Icon(Icons.system_update),
              title: Text(l10n.updateAvailable),
            ),
          const Divider(),
          ListTile(
            leading: const Icon(Icons.logout),
            title: Text(l10n.signOut),
            onTap: () => ref.read(authControllerProvider.notifier).signOut(),
          ),
          Padding(
            padding: const EdgeInsets.all(WgdSpacing.md),
            child: Text(
              l10n.versionLabel(installed),
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
        ],
      ),
    );
  }
}
