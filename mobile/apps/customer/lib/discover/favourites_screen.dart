import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import '../catalogue/catalogue_providers.dart';
import '../catalogue/widgets.dart';
import '../l10n/generated/app_localizations.dart';

/// Saved stores and products (spec 09 "Favourites").
class FavouritesScreen extends ConsumerWidget {
  const FavouritesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final favourites = ref.watch(favouritesProvider);
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: Text(l10n.favouritesTitle),
          bottom: TabBar(
            tabs: [
              Tab(text: l10n.storesTitle),
              Tab(text: l10n.productsTitle),
            ],
          ),
        ),
        body: favourites.when(
          loading: () => WgdStateView.loading(label: l10n.loading),
          error: (_, _) => WgdStateView.error(
            title: l10n.favouritesFailed,
            retryLabel: l10n.retry,
            onRetry: () => ref.invalidate(favouritesProvider),
          ),
          data: (f) => TabBarView(
            children: [
              f.vendors.isEmpty
                  ? WgdStateView.empty(
                      icon: Icons.favorite_border,
                      title: l10n.noFavouriteStores,
                    )
                  : ListView(
                      children: [
                        for (final v in f.vendors)
                          VendorCard(
                            vendor: v,
                            onTap: () => context.push('/v/${v.slug}'),
                          ),
                      ],
                    ),
              f.products.isEmpty
                  ? WgdStateView.empty(
                      icon: Icons.favorite_border,
                      title: l10n.noFavouriteProducts,
                    )
                  : ListView(
                      children: [
                        for (final p in f.products)
                          ProductTile(
                            product: p,
                            showVendor: true,
                            onTap: () => context.push('/p/${p.id}'),
                          ),
                      ],
                    ),
            ],
          ),
        ),
      ),
    );
  }
}
