import 'package:flutter/material.dart';

import '../tokens.dart';

/// Full-width primary action: orange with navy text, at least 48dp tall, with a busy state
/// that keeps its label for screen readers.
class WgdPrimaryButton extends StatelessWidget {
  const WgdPrimaryButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.busy = false,
    this.busyLabel,
  });

  final String label;
  final VoidCallback? onPressed;
  final bool busy;

  /// Read by screen readers while [busy]; defaults to [label].
  final String? busyLabel;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Semantics(
      button: true,
      enabled: onPressed != null && !busy,
      label: busy ? (busyLabel ?? label) : label,
      excludeSemantics: true,
      child: SizedBox(
        width: double.infinity,
        child: FilledButton(
          onPressed: busy ? null : onPressed,
          style: FilledButton.styleFrom(
            minimumSize: const Size.fromHeight(WgdSizes.minTapTarget),
          ),
          child: busy
              ? SizedBox.square(
                  dimension: 22,
                  child: CircularProgressIndicator(strokeWidth: 2.5, color: scheme.onPrimary),
                )
              : Text(label),
        ),
      ),
    );
  }
}
