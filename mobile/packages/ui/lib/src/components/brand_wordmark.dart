import 'package:flutter/material.dart';

import '../tokens.dart';

/// Text wordmark used until the final logo asset is supplied.
class WgdBrandWordmark extends StatelessWidget {
  const WgdBrandWordmark({super.key, this.subtitle, this.size = 32});

  /// "Vendor" or "Rider" for those apps.
  final String? subtitle;
  final double size;

  @override
  Widget build(BuildContext context) {
    final onSurface = Theme.of(context).colorScheme.onSurface;
    final label = subtitle == null ? 'WeGoDeliva' : 'WeGoDeliva $subtitle';
    return Semantics(
      header: true,
      label: label,
      excludeSemantics: true,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text.rich(
            TextSpan(
              children: [
                TextSpan(text: 'WeGo', style: TextStyle(color: onSurface)),
                const TextSpan(text: 'Deliva', style: TextStyle(color: WgdColors.orange)),
              ],
            ),
            style: TextStyle(fontSize: size, fontWeight: FontWeight.w800),
          ),
          if (subtitle != null)
            Text(
              subtitle!,
              style: Theme.of(context).textTheme.titleMedium?.copyWith(color: onSurface),
            ),
        ],
      ),
    );
  }
}
