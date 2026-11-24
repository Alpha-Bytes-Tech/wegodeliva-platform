import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'auth/auth_controller.dart';
import 'auth/auth_repository.dart';
import 'config/app_config.dart';
import 'logging/app_logger.dart';
import 'network/api_client.dart';
import 'push/push_service.dart';
import 'settings/app_settings.dart';
import 'settings/settings_repository.dart';
import 'storage/key_value_store.dart';
import 'storage/token_storage.dart';

/// Must be overridden in each app's `ProviderScope` with the flavour's configuration.
final appConfigProvider = Provider<AppConfig>(
  (ref) => throw UnimplementedError(
    'Override appConfigProvider in main_<flavor>.dart',
  ),
);

final loggerProvider = Provider<AppLogger>((ref) => AppLogger());

final tokenStorageProvider = Provider<TokenStorage>(
  (ref) => SecureTokenStorage(),
);

/// Non-secret preferences and caches.
final keyValueStoreProvider = Provider<KeyValueStore>(
  (ref) => SharedPreferencesStore(),
);

final settingsRepositoryProvider = Provider<SettingsRepository>(
  (ref) => SettingsRepository(
    ref.watch(apiClientProvider),
    ref.watch(keyValueStoreProvider),
    ref.watch(appConfigProvider).app,
  ),
);

/// Start-up settings, loaded once (and again when invalidated, e.g. on retry).
final appSettingsProvider = FutureProvider<AppSettings>(
  (ref) => ref.watch(settingsRepositoryProvider).load(),
);

final pushServiceProvider = Provider<PushService>(
  (ref) => const NoopPushService(),
);

final apiClientProvider = Provider<ApiClient>(
  (ref) => ApiClient(
    config: ref.watch(appConfigProvider),
    tokenStorage: ref.watch(tokenStorageProvider),
    logger: ref.watch(loggerProvider),
    // Read lazily: the controller itself depends on this client through the repository.
    onSessionRejected: () =>
        ref.read(authControllerProvider.notifier).sessionRejected(),
  ),
);

final authRepositoryProvider = Provider<AuthRepository>(
  (ref) => AuthRepository(
    ref.watch(apiClientProvider),
    ref.watch(appConfigProvider).app,
  ),
);

final authControllerProvider = NotifierProvider<AuthController, AuthState>(
  AuthController.new,
);
