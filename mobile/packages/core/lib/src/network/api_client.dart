import 'package:dio/dio.dart';

import '../config/app_config.dart';
import '../errors/api_error.dart';
import '../logging/app_logger.dart';
import '../storage/token_storage.dart';

/// Thin wrapper around Dio: base URL per flavour, JSON, bearer token from secure storage,
/// app version header, and every failure turned into an [ApiException].
class ApiClient {
  ApiClient({
    required AppConfig config,
    required TokenStorage tokenStorage,
    AppLogger? logger,
    Dio? dio,
  }) : _logger = logger ?? AppLogger(),
       dio =
           dio ??
           Dio(
             BaseOptions(
               connectTimeout: const Duration(seconds: 15),
               receiveTimeout: const Duration(seconds: 30),
             ),
           ) {
    this.dio.options
      ..baseUrl = config.apiBaseUrl
      ..headers.addAll({
        'Accept': 'application/json',
        'X-App-Version': config.appVersion,
        'X-App': config.app.apiValue,
      });
    this.dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final token = await tokenStorage.read();
          if (token != null) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          _logger.debug('→ ${options.method} ${options.path}');
          handler.next(options);
        },
      ),
    );
  }

  final Dio dio;
  final AppLogger _logger;

  /// Sends a request and returns the `data` member of the JSON response.
  Future<Object?> send(String method, String path, {Map<String, Object?>? body}) async {
    try {
      final response = await dio.request<Object?>(
        path,
        data: body,
        options: Options(method: method),
      );
      final data = response.data;
      return data is Map ? data['data'] : null;
    } on DioException catch (e) {
      final exception = toApiException(e);
      _logger.warning('← $method $path failed: ${exception.statusCode} ${exception.code}');
      throw exception;
    }
  }

  Future<Map<String, Object?>> postJson(String path, Map<String, Object?> body) async =>
      _asMap(await send('POST', path, body: body));

  Future<Map<String, Object?>> getJson(String path) async => _asMap(await send('GET', path));

  static Map<String, Object?> _asMap(Object? data) {
    if (data is Map) return Map<String, Object?>.from(data);
    throw const ApiException(ApiError.unknown);
  }

  /// Maps transport and HTTP errors to the API error model.
  static ApiException toApiException(DioException e) {
    final response = e.response;
    if (response == null) {
      return const ApiException(ApiError.network);
    }
    return ApiException(
      ApiError.fromResponseBody(response.data),
      statusCode: response.statusCode,
    );
  }
}
