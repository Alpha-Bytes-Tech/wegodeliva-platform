import 'package:flutter/material.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import '../l10n/generated/app_localizations.dart';

/// Tabs whose screens arrive in later milestones (search 3.3, orders 3.5, wallet 3.6).
class TabPlaceholder extends StatelessWidget {
  const TabPlaceholder({
    super.key,
    required this.title,
    required this.icon,
    required this.message,
  });

  final String title;
  final IconData icon;
  final String message;

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(title)),
    body: WgdStateView.empty(
      icon: icon,
      title: AppLocalizations.of(context).comingSoon,
      message: message,
    ),
  );
}
