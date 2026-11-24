import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import '../l10n/generated/app_localizations.dart';
import 'models.dart';

/// A private image through its signed link, with a neutral placeholder while loading or when
/// there is none. Decorative unless [label] is given.
class NetImage extends StatelessWidget {
  const NetImage(
    this.url, {
    super.key,
    this.label,
    this.icon = Icons.storefront_outlined,
    this.fit = BoxFit.cover,
  });

  final String? url;
  final String? label;
  final IconData icon;
  final BoxFit fit;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final placeholder = ColoredBox(
      color: scheme.surfaceContainerHighest,
      child: Center(child: Icon(icon, color: scheme.onSurfaceVariant)),
    );
    final image = url == null
        ? placeholder
        : CachedNetworkImage(
            imageUrl: url!,
            fit: fit,
            placeholder: (_, _) => placeholder,
            errorWidget: (_, _, _) => placeholder,
          );
    return Semantics(
      image: label != null,
      label: label,
      excludeSemantics: true,
      child: image,
    );
  }
}

class RatingLabel extends StatelessWidget {
  const RatingLabel({super.key, required this.average, required this.count});

  final double average;
  final int count;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    if (count == 0) {
      return Text(
        l10n.noRatingsYet,
        style: Theme.of(context).textTheme.bodySmall,
      );
    }
    return Semantics(
      label: l10n.ratingSemantics(average.toStringAsFixed(1), count),
      excludeSemantics: true,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.star_rounded, size: 18, color: WgdColors.orange),
          Text(
            ' ${average.toStringAsFixed(1)} ($count)',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}

class OpenStateChip extends StatelessWidget {
  const OpenStateChip(this.state, {super.key});

  final OpenState state;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final scheme = Theme.of(context).colorScheme;
    final (label, color) = switch (state) {
      OpenState.open => (l10n.openNow, Colors.green.shade700),
      OpenState.busy => (l10n.busy, Colors.orange.shade800),
      OpenState.closed => (l10n.closed, scheme.error),
    };
    return Text(
      label,
      style: Theme.of(context).textTheme.labelMedium?.copyWith(
        color: color,
        fontWeight: FontWeight.w600,
      ),
    );
  }
}

String distanceLabel(AppLocalizations l10n, int? metres) {
  if (metres == null) return '';
  return metres < 1000
      ? l10n.metresAway(metres)
      : l10n.kmAway((metres / 1000).toStringAsFixed(1));
}

/// A store in a list: cover, name, rating, open state, time and distance.
class VendorCard extends StatelessWidget {
  const VendorCard({super.key, required this.vendor, required this.onTap});

  final Vendor vendor;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final theme = Theme.of(context);
    final minutes = vendor.prepMinutes + vendor.deliveryMinutes;
    return Card(
      clipBehavior: Clip.antiAlias,
      margin: const EdgeInsets.symmetric(
        horizontal: WgdSpacing.md,
        vertical: WgdSpacing.sm,
      ),
      child: InkWell(
        onTap: onTap,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            AspectRatio(
              aspectRatio: 2.6,
              child: Opacity(
                opacity: vendor.isOpen ? 1 : 0.6,
                child: NetImage(vendor.coverUrl ?? vendor.logoUrl),
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(WgdSpacing.md),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    vendor.name,
                    style: theme.textTheme.titleMedium,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: WgdSpacing.xs),
                  Wrap(
                    spacing: WgdSpacing.md,
                    runSpacing: WgdSpacing.xs,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      OpenStateChip(vendor.openState),
                      RatingLabel(
                        average: vendor.ratingAverage,
                        count: vendor.ratingCount,
                      ),
                      if (minutes > 0)
                        Text(
                          l10n.minutesRange(minutes, minutes + 10),
                          style: theme.textTheme.bodySmall,
                        ),
                      if (vendor.distanceM != null)
                        Text(
                          distanceLabel(l10n, vendor.distanceM),
                          style: theme.textTheme.bodySmall,
                        ),
                      if (vendor.deliverable == false)
                        Text(
                          l10n.pickupOnlyHere,
                          style: theme.textTheme.bodySmall,
                        ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A product in a store menu or search results.
class ProductTile extends StatelessWidget {
  const ProductTile({
    super.key,
    required this.product,
    required this.onTap,
    this.showVendor = false,
  });

  final Product product;
  final VoidCallback onTap;
  final bool showVendor;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final theme = Theme.of(context);
    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(
          horizontal: WgdSpacing.md,
          vertical: WgdSpacing.sm,
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(product.name, style: theme.textTheme.titleSmall),
                  if (showVendor && product.vendorName != null)
                    Text(product.vendorName!, style: theme.textTheme.bodySmall),
                  if (product.description != null &&
                      product.description!.isNotEmpty)
                    Text(
                      product.description!,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall,
                    ),
                  const SizedBox(height: WgdSpacing.xs),
                  PriceLabel(product: product),
                  if (!product.inStock)
                    Text(
                      l10n.soldOut,
                      style: theme.textTheme.labelMedium?.copyWith(
                        color: theme.colorScheme.error,
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(width: WgdSpacing.md),
            ClipRRect(
              borderRadius: BorderRadius.circular(WgdSizes.radius),
              child: SizedBox.square(
                dimension: 80,
                child: NetImage(
                  product.imageUrls.firstOrNull,
                  icon: Icons.fastfood_outlined,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Selling price, with the crossed-out original when on promotion.
class PriceLabel extends StatelessWidget {
  const PriceLabel({super.key, required this.product});

  final Product product;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final theme = Theme.of(context);
    final unit = switch (product.pricingUnit) {
      'hour' => l10n.perHour,
      'day' => l10n.perDay,
      'month' => l10n.perMonth,
      _ => '',
    };
    return Wrap(
      spacing: WgdSpacing.sm,
      children: [
        Text(
          '${product.price}$unit',
          style: theme.textTheme.titleSmall?.copyWith(
            fontWeight: FontWeight.w700,
          ),
        ),
        if (product.originalPrice != null)
          Semantics(
            label: l10n.wasPrice(product.originalPrice.toString()),
            excludeSemantics: true,
            child: Text(
              product.originalPrice.toString(),
              style: theme.textTheme.bodySmall?.copyWith(
                decoration: TextDecoration.lineThrough,
              ),
            ),
          ),
      ],
    );
  }
}
