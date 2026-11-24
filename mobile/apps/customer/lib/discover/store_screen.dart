import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import '../catalogue/catalogue_providers.dart';
import '../catalogue/models.dart';
import '../catalogue/widgets.dart';
import '../l10n/generated/app_localizations.dart';

/// A store (spec 09 "Vendor page"): cover, rating, open state, delivery fee and time to the
/// chosen location, minimum order, menu sections and products; info and reviews tabs.
class StoreScreen extends ConsumerStatefulWidget {
  const StoreScreen({super.key, required this.slug});

  final String slug;

  @override
  ConsumerState<StoreScreen> createState() => _StoreScreenState();
}

class _StoreScreenState extends ConsumerState<StoreScreen> {
  String? _menu;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final vendor = ref.watch(vendorProvider(widget.slug));
    return vendor.when(
      loading: () => Scaffold(
        appBar: AppBar(),
        body: WgdStateView.loading(label: l10n.loading),
      ),
      error: (_, _) => Scaffold(
        appBar: AppBar(),
        body: WgdStateView.error(
          title: l10n.storeFailed,
          retryLabel: l10n.retry,
          onRetry: () => ref.invalidate(vendorProvider(widget.slug)),
        ),
      ),
      data: (v) => DefaultTabController(
        length: 3,
        child: Scaffold(
          appBar: AppBar(
            title: Text(v.name),
            actions: [_FavouriteVendorButton(vendor: v)],
            bottom: TabBar(
              tabs: [
                Tab(text: l10n.menuTab),
                Tab(text: l10n.infoTab),
                Tab(text: l10n.reviewsTab),
              ],
            ),
          ),
          body: TabBarView(
            children: [
              _MenuTab(
                vendor: v,
                menu: _menu,
                onMenu: (m) => setState(() => _menu = m),
              ),
              _InfoTab(vendor: v),
              _ReviewsTab(slug: v.slug),
            ],
          ),
        ),
      ),
    );
  }
}

class _FavouriteVendorButton extends ConsumerWidget {
  const _FavouriteVendorButton({required this.vendor});

  final Vendor vendor;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    ref.watch(favouritesProvider);
    final on = ref.read(favouritesProvider.notifier).isVendor(vendor.id);
    return IconButton(
      tooltip: on ? l10n.removeFavourite : l10n.addFavourite,
      icon: Icon(on ? Icons.favorite : Icons.favorite_border),
      onPressed: () => ref
          .read(favouritesProvider.notifier)
          .toggleVendor(vendor)
          .catchError((_) {
            if (context.mounted) {
              ScaffoldMessenger.of(
                context,
              ).showSnackBar(SnackBar(content: Text(l10n.favouriteFailed)));
            }
          }),
    );
  }
}

class _MenuTab extends ConsumerWidget {
  const _MenuTab({
    required this.vendor,
    required this.menu,
    required this.onMenu,
  });

  final Vendor vendor;
  final String? menu;
  final ValueChanged<String?> onMenu;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final quote = ref.watch(deliveryQuoteProvider(vendor.slug)).value;
    final products = ref.watch(
      storeProductsProvider((slug: vendor.slug, menu: menu)),
    );
    final theme = Theme.of(context);
    return CustomScrollView(
      slivers: [
        SliverToBoxAdapter(
          child: AspectRatio(
            aspectRatio: 2.6,
            child: NetImage(
              vendor.coverUrl ?? vendor.logoUrl,
              label: vendor.name,
            ),
          ),
        ),
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.all(WgdSpacing.md),
            child: Wrap(
              spacing: WgdSpacing.md,
              runSpacing: WgdSpacing.sm,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                OpenStateChip(vendor.openState),
                RatingLabel(
                  average: vendor.ratingAverage,
                  count: vendor.ratingCount,
                ),
                if (quote != null && quote.deliverable) ...[
                  Text(
                    l10n.deliveryFeeLabel(quote.fee.toString()),
                    style: theme.textTheme.bodySmall,
                  ),
                  if (quote.etaMin != null)
                    Text(
                      l10n.minutesRange(quote.etaMin!, quote.etaMax!),
                      style: theme.textTheme.bodySmall,
                    ),
                ],
                if (quote != null && !quote.deliverable)
                  Text(
                    l10n.noDeliveryHere,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.error,
                    ),
                  ),
                if (vendor.minOrder.kobo > 0)
                  Text(
                    l10n.minimumOrder(vendor.minOrder.toString()),
                    style: theme.textTheme.bodySmall,
                  ),
              ],
            ),
          ),
        ),
        if (!vendor.isOpen)
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: WgdSpacing.md),
              child: WgdStatusMessage.info(
                vendor.allowsScheduled
                    ? l10n.closedCanSchedule
                    : l10n.closedNoSchedule,
              ),
            ),
          ),
        if (vendor.menus.isNotEmpty)
          SliverToBoxAdapter(
            child: SizedBox(
              height: 56,
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(
                  horizontal: WgdSpacing.md,
                  vertical: WgdSpacing.xs,
                ),
                children: [
                  ChoiceChip(
                    label: Text(l10n.allItems),
                    selected: menu == null,
                    onSelected: (_) => onMenu(null),
                  ),
                  for (final m in vendor.menus) ...[
                    const SizedBox(width: WgdSpacing.sm),
                    ChoiceChip(
                      label: Text(m.name),
                      selected: menu == m.id,
                      onSelected: (_) => onMenu(m.id),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ...products.when(
          loading: () => [
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.all(WgdSpacing.lg),
                child: WgdStateView.loading(label: l10n.loading),
              ),
            ),
          ],
          error: (_, _) => [
            SliverToBoxAdapter(
              child: WgdStateView.error(
                title: l10n.productsFailed,
                retryLabel: l10n.retry,
                onRetry: () => ref.invalidate(
                  storeProductsProvider((slug: vendor.slug, menu: menu)),
                ),
              ),
            ),
          ],
          data: (list) => list.isEmpty
              ? [
                  SliverToBoxAdapter(
                    child: WgdStateView.empty(
                      icon: Icons.fastfood_outlined,
                      title: l10n.noProducts,
                    ),
                  ),
                ]
              : [
                  SliverList.separated(
                    itemCount: list.length,
                    separatorBuilder: (_, _) => const Divider(height: 1),
                    itemBuilder: (_, i) => ProductTile(
                      product: list[i],
                      onTap: () => context.push('/p/${list[i].id}'),
                    ),
                  ),
                ],
        ),
      ],
    );
  }
}

class _InfoTab extends StatelessWidget {
  const _InfoTab({required this.vendor});

  final Vendor vendor;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    // DateFormat weekday names: 2023-01-01 was a Sunday (weekday 0 in the API).
    String day(int weekday) =>
        DateFormat.EEEE().format(DateTime(2023, 1, 1 + weekday));
    final byDay = <int, List<OpeningHours>>{};
    for (final h in vendor.hours) {
      byDay.putIfAbsent(h.weekday, () => []).add(h);
    }
    return ListView(
      padding: const EdgeInsets.all(WgdSpacing.md),
      children: [
        if (vendor.description != null && vendor.description!.isNotEmpty) ...[
          Text(vendor.description!),
          const SizedBox(height: WgdSpacing.md),
        ],
        if (vendor.address != null)
          ListTile(
            contentPadding: EdgeInsets.zero,
            leading: const Icon(Icons.place_outlined),
            title: Text(vendor.address!),
          ),
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.local_shipping_outlined),
          title: Text(
            [
              if (vendor.acceptsDelivery) l10n.delivery,
              if (vendor.acceptsPickup) l10n.pickup,
            ].join(' · '),
          ),
        ),
        const SizedBox(height: WgdSpacing.sm),
        Semantics(
          header: true,
          child: Text(
            l10n.openingHours,
            style: Theme.of(context).textTheme.titleSmall,
          ),
        ),
        for (var d = 1; d <= 7; d++)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: WgdSpacing.xs),
            child: Row(
              children: [
                Expanded(child: Text(day(d % 7))),
                Text(
                  (byDay[d % 7] ?? const []).isEmpty
                      ? l10n.closed
                      : byDay[d % 7]!
                            .map(
                              (h) => h.opens == h.closes
                                  ? l10n.allDay
                                  : '${h.opens}–${h.closes}',
                            )
                            .join(', '),
                ),
              ],
            ),
          ),
      ],
    );
  }
}

class _ReviewsTab extends ConsumerWidget {
  const _ReviewsTab({required this.slug});

  final String slug;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    return ref
        .watch(storeReviewsProvider(slug))
        .when(
          loading: () => WgdStateView.loading(label: l10n.loading),
          error: (_, _) => WgdStateView.error(
            title: l10n.reviewsFailed,
            retryLabel: l10n.retry,
            onRetry: () => ref.invalidate(storeReviewsProvider(slug)),
          ),
          data: (list) => list.isEmpty
              ? WgdStateView.empty(
                  icon: Icons.rate_review_outlined,
                  title: l10n.noReviews,
                )
              : ListView.separated(
                  itemCount: list.length,
                  separatorBuilder: (_, _) => const Divider(height: 1),
                  itemBuilder: (_, i) {
                    final r = list[i];
                    return ListTile(
                      title: Semantics(
                        label: l10n.starsOutOfFive(r.rating),
                        excludeSemantics: true,
                        child: Text(
                          '★' * r.rating + '☆' * (5 - r.rating),
                          style: const TextStyle(color: WgdColors.orange),
                        ),
                      ),
                      subtitle: Text(
                        [
                          if (r.comment != null) r.comment!,
                          '${r.author ?? l10n.customer} · ${DateFormat.yMMMd().format(r.createdAt)}',
                        ].join('\n'),
                      ),
                    );
                  },
                ),
        );
  }
}
