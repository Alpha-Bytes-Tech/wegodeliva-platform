import 'package:freezed_annotation/freezed_annotation.dart';

import 'auth_user.dart';

part 'auth_session.freezed.dart';
part 'auth_session.g.dart';

/// A token issued by the backend for one app.
@freezed
abstract class AuthSession with _$AuthSession {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory AuthSession({
    required String token,
    required DateTime expiresAt,
    required String app,
    required AuthUser user,
  }) = _AuthSession;

  factory AuthSession.fromJson(Map<String, Object?> json) => _$AuthSessionFromJson(json);
}
