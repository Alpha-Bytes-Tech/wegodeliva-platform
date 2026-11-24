import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import '../catalogue/catalogue_providers.dart';
import '../catalogue/models.dart';
import '../catalogue/option_selection.dart';
import '../catalogue/widgets.dart';
import '../l10n/generated/app_localizations.dart';

/// What "Add to cart" does; the cart arrives in 3.4 and replaces this.
final addToCartProvider =
    Provider<void Function(BuildContext, OptionSelection)>(
      (ref) =>
          (context, selection) => ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(AppLocalizations.of(context).cartComingSoon),
            ),
          ),
    );

/// A product (spec 09 "Product details"): images, price, options with their rules, quantity and
/// a live total.
class ProductScreen extends ConsumerStatefulWidget {
  const ProductScreen({super.key, required this.id});

  final String id;

  @override
  ConsumerState<ProductScreen> createState() => _ProductScreenState();
}

class _ProductScreenState extends ConsumerState<ProductScreen> {
  OptionSelection? _selection;
  bool _showMissing = false;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final product = ref.watch(productProvider(widget.id));
    return product.when(
      loading: () => Scaffold(
        appBar: AppBar(),
        body: WgdStateView.loading(label: l10n.loading),
      ),
      error: (_, _) => Scaffold(
        appBar: AppBar(),
        body: WgdStateView.error(
          title: l10n.productFailed,
          retryLabel: l10n.retry,
          onRetry: () => ref.invalidate(productProvider(widget.id)),
        ),
      ),
      data: (p) {
        final selection = _selection ??= OptionSelection(p);
        return Scaffold(
          appBar: AppBar(
            title: Text(p.name),
            actions: [_FavouriteProductButton(product: p)],
          ),
          body: ListView(
            children: [
              AspectRatio(
                aspectRatio: 1.6,
                child: p.imageUrls.length <= 1
                    ? NetImage(
                        p.imageUrls.firstOrNull,
                        label: p.name,
                        icon: Icons.fastfood_outlined,
                      )
                    : PageView(
                        children: [
                          for (final url in p.imageUrls)
                            NetImage(url, label: p.name),
                        ],
                      ),
              ),
              Padding(
                padding: const EdgeInsets.all(WgdSpacing.md),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      p.name,
                      style: Theme.of(context).textTheme.headlineSmall,
                    ),
                    if (p.vendorName != null)
                      TextButton(
                        style: TextButton.styleFrom(padding: EdgeInsets.zero),
                        onPressed: p.vendorSlug == null
                            ? null
                            : () => context.push('/v/${p.vendorSlug}'),
                        child: Text(p.vendorName!),
                      ),
                    PriceLabel(product: p),
                    RatingLabel(average: p.ratingAverage, count: p.ratingCount),
                    if (p.requiresPrescription)
                      WgdStatusMessage.info(l10n.prescriptionNeeded),
                    if (!p.inStock) WgdStatusMessage.error(l10n.soldOut),
                    if (p.description != null && p.description!.isNotEmpty) ...[
                      const SizedBox(height: WgdSpacing.sm),
                      Text(p.description!),
                    ],
                  ],
                ),
              ),
              for (final g in p.optionGroups)
                _GroupSection(
                  group: g,
                  selection: selection,
                  showMissing: _showMissing,
                  onToggle: (o) =>
                      setState(() => _selection = selection.toggle(g, o)),
                ),
              const SizedBox(height: 96),
            ],
          ),
          bottomNavigationBar: SafeArea(
            child: Padding(
              padding: const EdgeInsets.all(WgdSpacing.md),
              child: Row(
                children: [
                  IconButton.outlined(
                    tooltip: l10n.decreaseQuantity,
                    onPressed: selection.quantity > 1
                        ? () => setState(
                            () => _selection = selection.withQuantity(
                              selection.quantity - 1,
                            ),
                          )
                        : null,
                    icon: const Icon(Icons.remove),
                  ),
                  Padding(
                    padding: const EdgeInsets.symmetric(
                      horizontal: WgdSpacing.md,
                    ),
                    child: Semantics(
                      label: l10n.quantityLabel(selection.quantity),
                      excludeSemantics: true,
                      child: Text(
                        '${selection.quantity}',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                    ),
                  ),
                  IconButton.outlined(
                    tooltip: l10n.increaseQuantity,
                    onPressed: () => setState(
                      () => _selection = selection.withQuantity(
                        selection.quantity + 1,
                      ),
                    ),
                    icon: const Icon(Icons.add),
                  ),
                  const SizedBox(width: WgdSpacing.md),
                  Expanded(
                    child: WgdPrimaryButton(
                      label: l10n.addToCart(
                        Money.ofKobo(selection.totalKobo).formatted,
                      ),
                      onPressed: !p.inStock || p.requiresPrescription
                          ? null
                          : () {
                              if (!selection.isValid) {
                                setState(() => _showMissing = true);
                                return;
                              }
                              ref.read(addToCartProvider)(context, selection);
                            },
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}

class _GroupSection extends StatelessWidget {
  const _GroupSection({
    required this.group,
    required this.selection,
    required this.showMissing,
    required this.onToggle,
  });

  final OptionGroup group;
  final OptionSelection selection;
  final bool showMissing;
  final ValueChanged<ProductOption> onToggle;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final theme = Theme.of(context);
    final missing = showMissing && selection.missing.contains(group);
    final rule = group.minimum > 0
        ? (group.maxSelect <= 1
              ? l10n.chooseOne
              : l10n.chooseBetween(group.minimum, group.maxSelect))
        : (group.maxSelect <= 1
              ? l10n.optional
              : l10n.chooseUpTo(group.maxSelect));
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Container(
          color: theme.colorScheme.surfaceContainerHighest,
          padding: const EdgeInsets.symmetric(
            horizontal: WgdSpacing.md,
            vertical: WgdSpacing.sm,
          ),
          child: Row(
            children: [
              Expanded(
                child: Semantics(
                  header: true,
                  child: Text(group.name, style: theme.textTheme.titleSmall),
                ),
              ),
              Text(
                rule,
                style: theme.textTheme.labelMedium?.copyWith(
                  color: missing ? theme.colorScheme.error : null,
                ),
              ),
            ],
          ),
        ),
        if (group.maxSelect <= 1)
          RadioGroup<String>(
            groupValue: group.options
                .where((x) => selection.isChosen(group, x))
                .firstOrNull
                ?.id,
            onChanged: (id) {
              // Tapping the chosen option of an optional group clears it (id is null).
              final picked =
                  id ??
                  group.options
                      .firstWhere((x) => selection.isChosen(group, x))
                      .id;
              onToggle(group.options.firstWhere((x) => x.id == picked));
            },
            child: Column(
              children: [
                for (final o in group.options)
                  RadioListTile<String>(
                    value: o.id,
                    toggleable: group.minimum == 0,
                    title: Text(o.name),
                    secondary: o.price.kobo > 0 ? Text('+${o.price}') : null,
                  ),
              ],
            ),
          )
        else
          for (final o in group.options)
            CheckboxListTile(
              value: selection.isChosen(group, o),
              onChanged: (_) => onToggle(o),
              title: Text(o.name),
              secondary: o.price.kobo > 0 ? Text('+${o.price}') : null,
            ),
      ],
    );
  }
}

class _FavouriteProductButton extends ConsumerWidget {
  const _FavouriteProductButton({required this.product});

  final Product product;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    ref.watch(favouritesProvider);
    final on = ref.read(favouritesProvider.notifier).isProduct(product.id);
    return IconButton(
      tooltip: on ? l10n.removeFavourite : l10n.addFavourite,
      icon: Icon(on ? Icons.favorite : Icons.favorite_border),
      onPressed: () => ref
          .read(favouritesProvider.notifier)
          .toggleProduct(product)
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
