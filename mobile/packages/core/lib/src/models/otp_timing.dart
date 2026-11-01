import 'package:freezed_annotation/freezed_annotation.dart';

part 'otp_timing.freezed.dart';
part 'otp_timing.g.dart';

/// How long a code lasts and when another may be requested, in seconds.
@freezed
abstract class OtpTiming with _$OtpTiming {
  @JsonSerializable(fieldRename: FieldRename.snake)
  const factory OtpTiming({required int expiresIn, required int resendIn}) = _OtpTiming;

  factory OtpTiming.fromJson(Map<String, Object?> json) => _$OtpTimingFromJson(json);
}
