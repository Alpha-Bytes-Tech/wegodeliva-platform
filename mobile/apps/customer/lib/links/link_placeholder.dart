import 'package:flutter/material.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import '../l10n/generated/app_localizations.dart';

/// Where store, product and order links land until their screens arrive (3.3 and 3.5). The
/// route already exists so links shared today keep working after an update.
class LinkPlaceholder extends StatelessWidget {
  const LinkPlaceholder({super.key, required this.title});

  final String title;

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(title)),
    body: WgdStateView.empty(
      icon: Icons.link,
      title: AppLocalizations.of(context).comingSoon,
      message: AppLocalizations.of(context).linkComingMessage,
    ),
  );
}
