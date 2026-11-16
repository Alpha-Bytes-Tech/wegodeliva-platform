import 'package:package_info_plus/package_info_plus.dart';

import 'client_app.dart';
import 'flavor.dart';

/// Per-build configuration. The API base URL can be overridden at build time with
/// `--dart-define=API_BASE_URL=https://…`.
class AppConfig {
  const AppConfig({
    required this.flavor,
    required this.app,
    required this.apiBaseUrl,
    required this.appVersion,
  });

  /// Builds the configuration for a flavour. Staging and production must use HTTPS; plain
  /// HTTP is only accepted for the dev flavour (local backend on an emulator).
  factory AppConfig.forFlavor({
    required Flavor flavor,
    required ClientApp app,
    required String appVersion,
    String? apiBaseUrlOverride,
  }) {
    const fromEnvironment = String.fromEnvironment('API_BASE_URL');
    final override =
        apiBaseUrlOverride ??
        (fromEnvironment.isEmpty ? null : fromEnvironment);
    final url = override ?? defaultBaseUrls[flavor]!;
    final uri = Uri.parse(url);
    if (uri.scheme != 'https' && flavor != Flavor.dev) {
      throw ArgumentError.value(
        url,
        'apiBaseUrl',
        'Only HTTPS is allowed outside dev builds',
      );
    }
    return AppConfig(
      flavor: flavor,
      app: app,
      apiBaseUrl: url,
      appVersion: appVersion,
    );
  }

  /// Builds the configuration with the installed app's real version (from the platform's
  /// package info), which the API receives as `X-App-Version`.
  static Future<AppConfig> load({
    required Flavor flavor,
    required ClientApp app,
    String? apiBaseUrlOverride,
  }) async {
    final info = await PackageInfo.fromPlatform();
    return AppConfig.forFlavor(
      flavor: flavor,
      app: app,
      appVersion: info.version,
      apiBaseUrlOverride: apiBaseUrlOverride,
    );
  }

  /// Placeholder hosts until staging/production are deployed (see docs/progress.md).
  static const defaultBaseUrls = <Flavor, String>{
    Flavor.dev: 'http://10.0.2.2:8000/api/v1',
    Flavor.staging: 'https://api-staging.wegodeliva.com/api/v1',
    Flavor.prod: 'https://api.wegodeliva.com/api/v1',
  };

  final Flavor flavor;
  final ClientApp app;
  final String apiBaseUrl;
  final String appVersion;
}
