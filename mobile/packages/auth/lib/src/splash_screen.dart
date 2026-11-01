import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

/// Restores the stored session, then the router moves on (spec 09 §9.1 "Splash").
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
    return Scaffold(
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            WgdBrandWordmark(subtitle: widget.appSubtitle),
            const SizedBox(height: WgdSpacing.lg),
            const CircularProgressIndicator(semanticsLabel: 'Loading'),
          ],
        ),
      ),
    );
  }
}
