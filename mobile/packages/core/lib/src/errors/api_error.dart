import 'package:freezed_annotation/freezed_annotation.dart';

part 'api_error.freezed.dart';
part 'api_error.g.dart';

/// The backend error envelope (docs/spec/14-api-behaviour.md §14.1):
/// `{ "error": { "code": "...", "message": "...", "fields": { ... } } }`.
@freezed
abstract class ApiError with _$ApiError {
  const factory ApiError({
    required String code,
    required String message,
    @Default(<String, List<String>>{}) Map<String, List<String>> fields,
    @Default(<String, Object?>{}) Map<String, Object?> meta,
  }) = _ApiError;

  factory ApiError.fromJson(Map<String, Object?> json) => _$ApiErrorFromJson(json);

  static const network = ApiError(
    code: 'network_error',
    message: 'Check your internet connection and try again.',
  );

  static const unknown = ApiError(
    code: 'server_error',
    message: 'Something went wrong on our side. Please try again.',
  );

  /// Reads an error envelope from any decoded response body.
  static ApiError fromResponseBody(Object? body) {
    if (body is Map && body['error'] is Map) {
      try {
        return ApiError.fromJson(Map<String, Object?>.from(body['error'] as Map));
      } on Object {
        return unknown;
      }
    }
    return unknown;
  }
}

/// Thrown by the API client for every failed request.
class ApiException implements Exception {
  const ApiException(this.error, {this.statusCode});

  final ApiError error;
  final int? statusCode;

  String get code => error.code;

  @override
  String toString() => 'ApiException($statusCode, ${error.code})';
}
