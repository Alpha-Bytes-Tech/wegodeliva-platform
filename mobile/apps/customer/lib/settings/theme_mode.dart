import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

/// Light, dark or follow the phone (spec 09 "Profile" → dark mode), remembered on the device.
class ThemeModeController extends Notifier<ThemeMode> {
  static const storeKey = 'theme_mode';

  @override
  ThemeMode build() {
    ref.read(keyValueStoreProvider).read(storeKey).then((value) {
      final saved = ThemeMode.values.where((m) => m.name == value).firstOrNull;
      if (saved != null && saved != state) state = saved;
    });
    return ThemeMode.system;
  }

  Future<void> set(ThemeMode mode) async {
    state = mode;
    await ref.read(keyValueStoreProvider).write(storeKey, mode.name);
  }
}

final themeModeProvider = NotifierProvider<ThemeModeController, ThemeMode>(
  ThemeModeController.new,
);
