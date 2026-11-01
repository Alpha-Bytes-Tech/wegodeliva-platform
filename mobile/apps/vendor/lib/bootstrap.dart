import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

import 'app.dart';

/// Starts the app for one flavour (see main_dev.dart, main_staging.dart, main_prod.dart).
void bootstrap(Flavor flavor) {
  WidgetsFlutterBinding.ensureInitialized();
  final config = AppConfig.forFlavor(flavor: flavor, app: ClientApp.vendor);
  runApp(
    ProviderScope(
      overrides: [appConfigProvider.overrideWithValue(config)],
      child: const VendorApp(),
    ),
  );
}
