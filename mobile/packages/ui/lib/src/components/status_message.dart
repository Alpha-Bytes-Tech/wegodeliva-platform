import 'package:flutter/material.dart';

import '../tokens.dart';

/// Inline error / info message, announced to screen readers when it appears.
class WgdStatusMessage extends StatelessWidget {
  const WgdStatusMessage.error(this.message, {super.key}) : isError = true;

  const WgdStatusMessage.info(this.message, {super.key}) : isError = false;

  final String message;
  final bool isError;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final color = isError ? scheme.error : scheme.onSurfaceVariant;
    final kind = isError ? 'Error' : 'Information';
    return Semantics(
      liveRegion: true,
      label: '$kind: $message',
      excludeSemantics: true,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: WgdSpacing.sm),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(
              isError ? Icons.error_outline : Icons.info_outline,
              color: color,
              semanticLabel: kind,
            ),
            const SizedBox(width: WgdSpacing.sm),
            Expanded(
              child: Text(message, style: TextStyle(color: color)),
            ),
          ],
        ),
      ),
    );
  }
}
