import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import '../catalogue/catalogue_providers.dart';
import '../catalogue/models.dart';
import '../catalogue/widgets.dart';
import '../l10n/generated/app_localizations.dart';
import '../location/address_chip.dart';
import '../location/delivery_location.dart';

/// Home (spec 09 §9.2): address chip, search, banners, store types, open near you, featured.
/// The reorder strip arrives with the orders list (3.5).
class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  static const _icons = {
    'food': Icons.restaurant,
    'groceries': Icons.local_grocery_store_outlined,
    'pharmacy': Icons.local_pharmacy_outlined,
    'fashion': Icons.checkroom,
    'parcels': Icons.local_shipping_outlined,
    'services': Icons.home_repair_service_outlined,
  };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);
    final name = auth is SignedIn ? auth.user.name.split(' ').first : '';
    final location = ref.watch(deliveryLocationProvider);
    final types = ref.watch(vendorTypesProvider);
    final banners = ref.watch(bannersProvider(null));
    const filter = VendorFilter();
    final vendors = ref.watch(vendorsProvider(filter));

    Future<void> refresh() async {
      ref
        ..invalidate(vendorTypesProvider)
        ..invalidate(bannersProvider(null))
        ..invalidate(vendorsProvider(filter));
      await ref
          .read(vendorsProvider(filter).future)
          .catchError((_) => <Vendor>[]);
    }

    return Scaffold(
      appBar: AppBar(title: const AddressChip(), toolbarHeight: 64),
      body: RefreshIndicator(
        onRefresh: refresh,
        child: CustomScrollView(
          slivers: [
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(
                  WgdSpacing.md,
                  WgdSpacing.sm,
                  WgdSpacing.md,
                  0,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      l10n.homeGreeting(name),
                      style: Theme.of(context).textTheme.headlineSmall,
                    ),
                    const SizedBox(height: WgdSpacing.md),
                    Semantics(
                      button: true,
                      label: l10n.searchHint,
                      excludeSemantics: true,
                      child: OutlinedButton.icon(
                        style: OutlinedButton.styleFrom(
                          alignment: Alignment.centerLeft,
                        ),
                        onPressed: () => context.go('/search'),
                        icon: const Icon(Icons.search),
                        label: Text(l10n.searchHint),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            if ((banners.value ?? const []).isNotEmpty)
              SliverToBoxAdapter(child: _Banners(banners.value!)),
            SliverToBoxAdapter(
              child: SizedBox(
                height: 104,
                child: types.when(
                  loading: () => const SizedBox.shrink(),
                  error: (_, _) => const SizedBox.shrink(),
                  data: (list) => ListView(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.symmetric(
                      horizontal: WgdSpacing.sm,
                      vertical: WgdSpacing.md,
                    ),
                    children: [
                      for (final t in list)
                        _TypeTile(
                          label: t.name,
                          icon: _icons[t.slug] ?? Icons.storefront_outlined,
                          onTap: () =>
                              context.push('/type/${t.slug}', extra: t.name),
                        ),
                    ],
                  ),
                ),
              ),
            ),
            if (location.hasValue && location.value == null)
              SliverFillRemaining(
                hasScrollBody: false,
                child: WgdStateView.empty(
                  icon: Icons.location_off_outlined,
                  title: l10n.chooseLocation,
                  message: l10n.chooseLocationMessage,
                  actionLabel: l10n.chooseLocation,
                  onAction: () => showLocationPicker(context),
                ),
              )
            else
              ...vendors.when(
                loading: () => [
                  SliverFillRemaining(
                    hasScrollBody: false,
                    child: WgdStateView.loading(label: l10n.loading),
                  ),
                ],
                error: (_, _) => [
                  SliverFillRemaining(
                    hasScrollBody: false,
                    child: WgdStateView.error(
                      title: l10n.storesFailed,
                      retryLabel: l10n.retry,
                      onRetry: () => ref.invalidate(vendorsProvider(filter)),
                    ),
                  ),
                ],
                data: (list) {
                  if (list.isEmpty) {
                    return [
                      SliverFillRemaining(
                        hasScrollBody: false,
                        child: WgdStateView.empty(
                          icon: Icons.storefront_outlined,
                          title: l10n.noStoresNear,
                          message: l10n.noStoresNearMessage,
                        ),
                      ),
                    ];
                  }
                  final featured = list.where((v) => v.featured).toList();
                  final open = list.where((v) => v.isOpen).toList();
                  return [
                    if (featured.isNotEmpty) ...[
                      _heading(context, l10n.featured),
                      SliverList.list(
                        children: [
                          for (final v in featured)
                            VendorCard(
                              vendor: v,
                              onTap: () => context.push('/v/${v.slug}'),
                            ),
                        ],
                      ),
                    ],
                    _heading(context, l10n.openNearYou),
                    if (open.isEmpty)
                      SliverToBoxAdapter(
                        child: Padding(
                          padding: const EdgeInsets.all(WgdSpacing.md),
                          child: Text(l10n.noneOpenNow),
                        ),
                      )
                    else
                      SliverList.list(
                        children: [
                          for (final v in open)
                            VendorCard(
                              vendor: v,
                              onTap: () => context.push('/v/${v.slug}'),
                            ),
                        ],
                      ),
                    const SliverToBoxAdapter(
                      child: SizedBox(height: WgdSpacing.lg),
                    ),
                  ];
                },
              ),
          ],
        ),
      ),
    );
  }

  static Widget _heading(BuildContext context, String text) =>
      SliverToBoxAdapter(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(
            WgdSpacing.md,
            WgdSpacing.md,
            WgdSpacing.md,
            WgdSpacing.xs,
          ),
          child: Semantics(
            header: true,
            child: Text(text, style: Theme.of(context).textTheme.titleLarge),
          ),
        ),
      );
}

class _TypeTile extends StatelessWidget {
  const _TypeTile({
    required this.label,
    required this.icon,
    required this.onTap,
  });

  final String label;
  final IconData icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: WgdSpacing.xs),
    child: InkWell(
      borderRadius: BorderRadius.circular(WgdSizes.radius),
      onTap: onTap,
      child: SizedBox(
        width: 84,
        child: Column(
          children: [
            CircleAvatar(radius: 24, child: Icon(icon)),
            const SizedBox(height: WgdSpacing.xs),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.labelMedium,
            ),
          ],
        ),
      ),
    ),
  );
}

class _Banners extends StatelessWidget {
  const _Banners(this.banners);

  final List<Slide> banners;

  @override
  Widget build(BuildContext context) => SizedBox(
    height: 150,
    child: PageView(
      controller: PageController(viewportFraction: 0.9),
      children: [
        for (final b in banners)
          Padding(
            padding: const EdgeInsets.all(WgdSpacing.sm),
            child: InkWell(
              borderRadius: BorderRadius.circular(WgdSizes.radius),
              onTap: b.link == null ? null : () => context.push(b.link!),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(WgdSizes.radius),
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    NetImage(
                      b.imageUrl,
                      label: b.title,
                      icon: Icons.campaign_outlined,
                    ),
                    Align(
                      alignment: Alignment.bottomLeft,
                      child: Container(
                        width: double.infinity,
                        color: Colors.black54,
                        padding: const EdgeInsets.all(WgdSpacing.sm),
                        child: Text(
                          b.title,
                          style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
      ],
    ),
  );
}
