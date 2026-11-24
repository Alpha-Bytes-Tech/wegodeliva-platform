import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import '../catalogue/catalogue_providers.dart';
import '../catalogue/widgets.dart';
import '../l10n/generated/app_localizations.dart';

/// A store type's page (spec 09 "Vendor type landing"): categories, "open now", sort by
/// distance or rating.
class VendorTypeScreen extends ConsumerStatefulWidget {
  const VendorTypeScreen({super.key, required this.slug, this.title});

  final String slug;
  final String? title;

  @override
  ConsumerState<VendorTypeScreen> createState() => _VendorTypeScreenState();
}

class _VendorTypeScreenState extends ConsumerState<VendorTypeScreen> {
  late VendorFilter _filter = VendorFilter(type: widget.slug);

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final categories =
        ref.watch(categoriesProvider(widget.slug)).value ?? const [];
    final vendors = ref.watch(vendorsProvider(_filter));
    return Scaffold(
      appBar: AppBar(title: Text(widget.title ?? l10n.storesTitle)),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            height: 56,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(
                horizontal: WgdSpacing.md,
                vertical: WgdSpacing.xs,
              ),
              children: [
                FilterChip(
                  label: Text(l10n.openNow),
                  selected: _filter.openOnly,
                  onSelected: (on) =>
                      setState(() => _filter = _filter.copyWith(openOnly: on)),
                ),
                const SizedBox(width: WgdSpacing.sm),
                ChoiceChip(
                  label: Text(l10n.sortRating),
                  selected: _filter.sort == VendorSort.rating,
                  onSelected: (on) => setState(
                    () => _filter = _filter.copyWith(
                      sort: on ? VendorSort.rating : VendorSort.distance,
                    ),
                  ),
                ),
                for (final c in categories) ...[
                  const SizedBox(width: WgdSpacing.sm),
                  ChoiceChip(
                    label: Text(c.name),
                    selected: _filter.category == c.slug,
                    onSelected: (on) => setState(
                      () => _filter = on
                          ? _filter.copyWith(category: c.slug)
                          : _filter.copyWith(clearCategory: true),
                    ),
                  ),
                ],
              ],
            ),
          ),
          Expanded(
            child: vendors.when(
              loading: () => WgdStateView.loading(label: l10n.loading),
              error: (_, _) => WgdStateView.error(
                title: l10n.storesFailed,
                retryLabel: l10n.retry,
                onRetry: () => ref.invalidate(vendorsProvider(_filter)),
              ),
              data: (list) => list.isEmpty
                  ? WgdStateView.empty(
                      icon: Icons.storefront_outlined,
                      title: l10n.noStoresMatch,
                    )
                  : ListView(
                      children: [
                        for (final v in list)
                          VendorCard(
                            vendor: v,
                            onTap: () => context.push('/v/${v.slug}'),
                          ),
                      ],
                    ),
            ),
          ),
        ],
      ),
    );
  }
}
