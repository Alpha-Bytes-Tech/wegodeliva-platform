import 'package:flutter/material.dart';

import 'tokens.dart';

/// The single Material 3 theme for all three apps (light and dark).
abstract final class WgdTheme {
  static ThemeData light() => _build(
    ColorScheme.fromSeed(seedColor: WgdColors.orange, brightness: Brightness.light).copyWith(
      primary: WgdColors.orange,
      onPrimary: WgdColors.navy,
      secondary: WgdColors.navy,
      onSecondary: Colors.white,
      surface: WgdColors.surfaceLight,
      onSurface: WgdColors.navy,
      onSurfaceVariant: WgdColors.textMutedLight,
      error: WgdColors.errorLight,
    ),
  );

  static ThemeData dark() => _build(
    ColorScheme.fromSeed(seedColor: WgdColors.orange, brightness: Brightness.dark).copyWith(
      primary: WgdColors.orange,
      onPrimary: WgdColors.navy,
      secondary: WgdColors.textDark,
      onSecondary: WgdColors.navy,
      surface: WgdColors.surfaceDark,
      onSurface: WgdColors.textDark,
      surfaceContainerHighest: WgdColors.surfaceDarkHigh,
      error: WgdColors.errorDark,
    ),
  );

  static ThemeData _build(ColorScheme scheme) {
    final shape = RoundedRectangleBorder(borderRadius: BorderRadius.circular(WgdSizes.radius));
    const minSize = Size(WgdSizes.minTapTarget, WgdSizes.minTapTarget);
    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: scheme.brightness == Brightness.light
          ? WgdColors.backgroundLight
          : WgdColors.backgroundDark,
      materialTapTargetSize: MaterialTapTargetSize.padded,
      visualDensity: VisualDensity.standard,
      appBarTheme: AppBarTheme(
        backgroundColor: scheme.surface,
        foregroundColor: scheme.onSurface,
        centerTitle: false,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: scheme.primary,
          foregroundColor: scheme.onPrimary,
          minimumSize: minSize,
          shape: shape,
          textStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(minimumSize: minSize, shape: shape),
      ),
      textButtonTheme: TextButtonThemeData(style: TextButton.styleFrom(minimumSize: minSize)),
      iconButtonTheme: IconButtonThemeData(style: IconButton.styleFrom(minimumSize: minSize)),
      inputDecorationTheme: InputDecorationTheme(
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(WgdSizes.radius)),
        constraints: const BoxConstraints(minHeight: WgdSizes.minTapTarget),
      ),
    );
  }
}
