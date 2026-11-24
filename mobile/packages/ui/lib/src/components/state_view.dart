import 'package:flutter/material.dart';

import '../tokens.dart';

/// Full-area loading, empty and error states, used the same way on every screen. Texts come
/// from the calling app's localisations.
class WgdStateView extends StatelessWidget {
  const WgdStateView.loading({super.key, required String label})
    : _kind = _Kind.loading,
      title = label,
      message = null,
      icon = null,
      actionLabel = null,
      onAction = null;

  const WgdStateView.empty({
    super.key,
    required this.title,
    this.message,
    this.icon = Icons.inbox_outlined,
    this.actionLabel,
    this.onAction,
  }) : _kind = _Kind.empty;

  /// An error with a retry button.
  const WgdStateView.error({
    super.key,
    required this.title,
    this.message,
    required String retryLabel,
    required VoidCallback onRetry,
  }) : _kind = _Kind.error,
       icon = Icons.cloud_off_outlined,
       actionLabel = retryLabel,
       onAction = onRetry;

  final _Kind _kind;
  final String title;
  final String? message;
  final IconData? icon;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (_kind == _Kind.loading) {
      return Center(child: CircularProgressIndicator(semanticsLabel: title));
    }
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(WgdSpacing.lg),
        child: Semantics(
          liveRegion: _kind == _Kind.error,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                icon,
                size: 56,
                color: _kind == _Kind.error
                    ? theme.colorScheme.error
                    : theme.colorScheme.onSurfaceVariant,
                semanticLabel: _kind == _Kind.error ? 'Error' : null,
              ),
              const SizedBox(height: WgdSpacing.md),
              Text(
                title,
                style: theme.textTheme.titleMedium,
                textAlign: TextAlign.center,
              ),
              if (message != null) ...[
                const SizedBox(height: WgdSpacing.sm),
                Text(
                  message!,
                  textAlign: TextAlign.center,
                  style: TextStyle(color: theme.colorScheme.onSurfaceVariant),
                ),
              ],
              if (actionLabel != null && onAction != null) ...[
                const SizedBox(height: WgdSpacing.lg),
                _kind == _Kind.error
                    ? FilledButton(
                        onPressed: onAction,
                        child: Text(actionLabel!),
                      )
                    : OutlinedButton(
                        onPressed: onAction,
                        child: Text(actionLabel!),
                      ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

enum _Kind { loading, empty, error }
