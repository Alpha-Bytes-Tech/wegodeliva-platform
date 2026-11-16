import '../config/client_app.dart';
import '../models/auth_session.dart';
import '../models/auth_user.dart';
import '../models/otp_timing.dart';
import '../network/api_client.dart';

/// Auth endpoints of the backend (`/api/v1/auth/...`).
class AuthRepository {
  AuthRepository(this._client, this._app);

  final ApiClient _client;
  final ClientApp _app;

  ClientApp get app => _app;

  /// Emails a sign-in code. The backend answers the same whether or not the account exists.
  Future<OtpTiming> sendOtp(String email) async {
    final data = await _client.postJson('/auth/otp/send', {
      'email': email.trim(),
      'app': _app.apiValue,
      'purpose': 'login',
    });
    return OtpTiming.fromJson(Map<String, Object?>.from(data['otp']! as Map));
  }

  Future<AuthSession> verifyOtp(String email, String code, {String? deviceName}) async {
    final data = await _client.postJson('/auth/otp/verify', {
      'email': email.trim(),
      'code': code,
      'app': _app.apiValue,
      'device_name': ?deviceName,
    });
    return AuthSession.fromJson(data);
  }

  Future<AuthUser> me() async => AuthUser.fromJson(await _client.getJson('/auth/me'));

  Future<void> logout() async {
    await _client.send('POST', '/auth/logout');
  }
}
