import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:mocktail/mocktail.dart';
import 'package:wegodeliva_auth/wegodeliva_auth.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

class MockAuthRepository extends Mock implements AuthRepository {}

const testUser = AuthUser(
  uuid: 'u-1',
  name: 'Ada Obi',
  email: 'ada@example.com',
  phone: '+2348031234567',
  status: 'active',
  emailVerified: true,
  roles: ['customer'],
);

ApiException apiError(String code, String message, {int status = 422, Map<String, Object?>? meta}) =>
    ApiException(
      ApiError(code: code, message: message, meta: meta ?? const {}),
      statusCode: status,
    );

Widget wrap(Widget child, {required List<dynamic> overrides, ProviderContainer? container}) {
  final app = MaterialApp(
    theme: WgdTheme.light(),
    localizationsDelegates: AuthLocalizations.localizationsDelegates,
    supportedLocales: AuthLocalizations.supportedLocales,
    home: child,
  );
  if (container != null) {
    return UncontrolledProviderScope(container: container, child: app);
  }
  return ProviderScope(overrides: overrides.cast(), child: app);
}
