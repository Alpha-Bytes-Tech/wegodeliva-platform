import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import '../l10n/generated/app_localizations.dart';

final contentPageProvider =
    FutureProvider.family<({String title, String body}), String>((
      ref,
      slug,
    ) async {
      final data = await ref
          .watch(apiClientProvider)
          .getJson('/app/pages/$slug');
      return (title: data['title'].toString(), body: data['body'].toString());
    });

/// A legal or help page (terms, privacy, FAQ). Shown as plain text; Markdown styling comes
/// with the profile screens (3.6).
class ContentPageScreen extends ConsumerWidget {
  const ContentPageScreen({super.key, required this.slug});

  final String slug;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final page = ref.watch(contentPageProvider(slug));
    return Scaffold(
      appBar: AppBar(title: Text(page.value?.title ?? '')),
      body: page.when(
        loading: () => WgdStateView.loading(label: l10n.loading),
        error: (_, _) => WgdStateView.error(
          title: l10n.loadFailed,
          retryLabel: l10n.retry,
          onRetry: () => ref.invalidate(contentPageProvider(slug)),
        ),
        data: (p) => SingleChildScrollView(
          padding: const EdgeInsets.all(WgdSpacing.lg),
          child: SelectableText(
            p.body.replaceAll(RegExp(r'^#+\s*', multiLine: true), ''),
          ),
        ),
      ),
    );
  }
}
