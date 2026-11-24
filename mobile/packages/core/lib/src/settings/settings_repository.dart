import 'dart:convert';

import '../config/client_app.dart';
import '../errors/api_error.dart';
import '../network/api_client.dart';
import '../storage/key_value_store.dart';
import 'app_settings.dart';

/// Loads start-up settings from the API and keeps the last good copy, so the app still opens
/// with sensible flags when the network is down (spec 09 §9.8 "Offline").
class SettingsRepository {
  SettingsRepository(this._client, this._store, this._app);

  static const cacheKey = 'app_settings';

  final ApiClient _client;
  final KeyValueStore _store;
  final ClientApp _app;

  Future<AppSettings> load() async {
    try {
      final data = await _client.getJson('/app/settings?app=${_app.apiValue}');
      await _store.write(cacheKey, jsonEncode(data));
      return AppSettings.fromJson(data);
    } on ApiException catch (e) {
      if (e.code != ApiError.network.code) rethrow;
      final cached = await cachedSettings();
      if (cached == null) rethrow;
      return cached;
    }
  }

  Future<AppSettings?> cachedSettings() async {
    final raw = await _store.read(cacheKey);
    if (raw == null) return null;
    try {
      return AppSettings.fromJson(
        Map<String, Object?>.from(jsonDecode(raw) as Map),
      );
    } on Object {
      return null;
    }
  }
}
