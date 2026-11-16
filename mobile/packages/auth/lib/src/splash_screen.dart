import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import '../l10n/generated/auth_localizations.dart';

/// Restores the stored session, then the router moves on (spec 09 §9.1 "Splash"). While the
/// server cannot be reached ([SessionOffline]) it keeps the stored token and offers a retry.
/// Remote config and the forced-update check join here in Phase 3.
class SplashScreen extends ConsumerStatefulWidget {
  const SplashScreen({super.key, this.appSubtitle});

  final String? appSubtitle;

  @override
  ConsumerState<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends ConsumerState<SplashScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) ref.read(authControllerProvider.notifier).restore();
    });
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final l10n = AuthLocalizations.of(context);
    return Scaffold(
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(WgdSpacing.lg),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              WgdBrandWordmark(subtitle: widget.appSubtitle),
              const SizedBox(height: WgdSpacing.lg),
              if (auth is SessionOffline) ...[
                Text(
                  l10n.offlineTitle,
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: WgdSpacing.sm),
                Text(l10n.offlineMessage, textAlign: TextAlign.center),
                const SizedBox(height: WgdSpacing.lg),
                FilledButton(
                  onPressed: () =>
                      ref.read(authControllerProvider.notifier).retry(),
                  child: Text(l10n.retryButton),
                ),
              ] else
                const CircularProgressIndicator(semanticsLabel: 'Loading'),
            ],
          ),
        ),
      ),
    );
  }
}
