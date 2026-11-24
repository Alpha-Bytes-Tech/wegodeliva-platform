import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import '../l10n/generated/app_localizations.dart';
import '../location/address_chip.dart';

/// Home (spec 09 §9.2). The address chip is live; stores, banners and the reorder strip
/// arrive with Discover (3.3).
class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);
    final name = auth is SignedIn ? auth.user.name.split(' ').first : '';
    return Scaffold(
      appBar: AppBar(title: const AddressChip(), toolbarHeight: 64),
      body: ListView(
        padding: const EdgeInsets.all(WgdSpacing.lg),
        children: [
          Text(
            l10n.homeGreeting(name),
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          const SizedBox(height: WgdSpacing.xl),
          WgdStateView.empty(
            icon: Icons.storefront_outlined,
            title: l10n.homeComingTitle,
            message: l10n.homeComingMessage,
          ),
        ],
      ),
    );
  }
}
