import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

import 'app.dart';

/// Starts the app for one flavour (see main_dev.dart, main_staging.dart, main_prod.dart).
/// The configuration carries the installed version, sent to the API as `X-App-Version`.
Future<void> bootstrap(Flavor flavor) async {
  WidgetsFlutterBinding.ensureInitialized();
  final config = await AppConfig.load(flavor: flavor, app: ClientApp.vendor);
  runApp(
    ProviderScope(
      overrides: [appConfigProvider.overrideWithValue(config)],
      child: const VendorApp(),
    ),
  );
}
