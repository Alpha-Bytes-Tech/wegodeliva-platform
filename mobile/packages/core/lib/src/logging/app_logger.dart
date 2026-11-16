import 'dart:developer' as developer;

import 'package:flutter/foundation.dart';

typedef LogSink = void Function(String level, String message);

/// Logging that never writes user data (CLAUDE.md §4): emails, phone numbers, bearer tokens
/// and 6-digit codes are masked, and nothing is logged in release builds by default.
class AppLogger {
  AppLogger({LogSink? sink, bool? enabled})
    : _sink = sink ?? _developerSink,
      _enabled = enabled ?? kDebugMode;

  final LogSink _sink;
  final bool _enabled;

  void debug(String message) => _write('DEBUG', message);
  void info(String message) => _write('INFO', message);
  void warning(String message) => _write('WARN', message);
  void error(String message, [Object? error]) =>
      _write('ERROR', error == null ? message : '$message: ${error.runtimeType}');

  void _write(String level, String message) {
    if (!_enabled) return;
    _sink(level, redact(message));
  }

  static final _patterns = <RegExp, String>{
    RegExp(r'Bearer\s+[A-Za-z0-9|._\-]+', caseSensitive: false): 'Bearer [token]',
    RegExp(r'[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}'): '[email]',
    RegExp(r'\+?\d[\d\s\-]{8,}\d'): '[phone]',
    RegExp(r'\b\d{6}\b'): '[code]',
  };

  /// Masks personal data and secrets in a log line.
  static String redact(String message) {
    var result = message;
    _patterns.forEach((pattern, replacement) {
      result = result.replaceAll(pattern, replacement);
    });
    return result;
  }

  static void _developerSink(String level, String message) {
    developer.log(message, name: 'wegodeliva', level: level == 'ERROR' ? 1000 : 800);
  }
}
