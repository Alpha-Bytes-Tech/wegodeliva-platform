import 'package:freezed_annotation/freezed_annotation.dart';

part 'auth_user.freezed.dart';
part 'auth_user.g.dart';

/// The signed-in user as returned by `GET /auth/me`.
@freezed
abstract class AuthUser with _$AuthUser {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory AuthUser({
    required String uuid,
    required String name,
    required String email,
    required String phone,
    required String status,
    @Default(false) bool emailVerified,
    @Default(<String>[]) List<String> roles,
  }) = _AuthUser;

  factory AuthUser.fromJson(Map<String, Object?> json) => _$AuthUserFromJson(json);
}
