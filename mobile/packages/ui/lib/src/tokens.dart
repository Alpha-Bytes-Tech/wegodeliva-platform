import 'package:flutter/material.dart';

/// Brand tokens (CLAUDE.md §4 "Design"). Mirrored in web/src/app/globals.css.
abstract final class WgdColors {
  /// Base colour: app bars, dark surfaces, text on orange.
  static const navy = Color(0xFF0B1220);

  /// Accent for primary actions. Always pair with [navy] text: white on orange fails WCAG AA.
  static const orange = Color(0xFFFF6B1A);

  static const orangePressed = Color(0xFFE85D10);
  static const surfaceLight = Color(0xFFFFFFFF);
  static const backgroundLight = Color(0xFFF6F7F9);
  static const textMutedLight = Color(0xFF4A5468);
  static const surfaceDark = Color(0xFF0B1220);
  static const backgroundDark = Color(0xFF070B14);
  static const surfaceDarkHigh = Color(0xFF151D2E);
  static const textDark = Color(0xFFE8EBF2);
  static const errorLight = Color(0xFFB3261E);
  static const errorDark = Color(0xFFFFB4AB);
}

abstract final class WgdSpacing {
  static const xs = 4.0;
  static const sm = 8.0;
  static const md = 16.0;
  static const lg = 24.0;
  static const xl = 32.0;
}

abstract final class WgdSizes {
  /// Minimum tap target (Material / CLAUDE.md accessibility rule).
  static const minTapTarget = 48.0;
  static const radius = 12.0;
}
