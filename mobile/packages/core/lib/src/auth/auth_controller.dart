import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../errors/api_error.dart';
import '../models/auth_session.dart';
import '../models/auth_user.dart';
import '../providers.dart';

/// App-wide sign-in state. The router listens to it to guard screens.
sealed class AuthState {
  const AuthState();
}

/// Not known yet (splash: reading the stored token).
class AuthUnknown extends AuthState {
  const AuthUnknown();
}

/// A token is stored but the server could not be reached (offline, timeout or a 5xx) to
/// confirm it. The token is kept and trusted; [AuthController] retries `me()` with backoff,
/// and the user can retry at once with [AuthController.retry].
class SessionOffline extends AuthState {
  const SessionOffline({required this.attempt, required this.nextRetryIn});

  /// How many restore attempts have failed so far (1 after the first failure).
  final int attempt;

  /// When the next automatic retry runs.
  final Duration nextRetryIn;
}

class SignedOut extends AuthState {
  const SignedOut();
}

class SignedIn extends AuthState {
  const SignedIn(this.user);

  final AuthUser user;
}

/// Delay before automatic retry number `attempt` (1-based) while the session is offline:
/// 2 s, 4 s, 8 s … capped at one minute. Overridden in tests.
final sessionRetryDelayProvider = Provider<Duration Function(int attempt)>(
  (ref) =>
      (attempt) => Duration(seconds: (1 << attempt.clamp(1, 6)).clamp(2, 60)),
);

class AuthController extends Notifier<AuthState> {
  Timer? _retryTimer;
  int _failedAttempts = 0;

  @override
  AuthState build() {
    ref.onDispose(_cancelRetry);
    return const AuthUnknown();
  }

  /// Called by the splash screen: restores a stored session if the token is still valid.
  ///
  /// Only a 401 or 403 from the server clears the token. Network and server errors keep it
  /// and move to [SessionOffline], which retries on its own.
  Future<void> restore() async {
    _cancelRetry();
    final storage = ref.read(tokenStorageProvider);
    final token = await storage.read();
    if (token == null) {
      _failedAttempts = 0;
      state = const SignedOut();
      return;
    }
    try {
      final user = await ref.read(authRepositoryProvider).me();
      _failedAttempts = 0;
      state = SignedIn(user);
    } on ApiException catch (e) {
      if (e.statusCode == 401 || e.statusCode == 403) {
        _failedAttempts = 0;
        await storage.clear();
        state = const SignedOut();
        return;
      }
      _failedAttempts++;
      final delay = ref.read(sessionRetryDelayProvider)(_failedAttempts);
      ref
          .read(loggerProvider)
          .warning(
            'Session restore failed (${e.code}); retrying in ${delay.inSeconds} s',
          );
      state = SessionOffline(attempt: _failedAttempts, nextRetryIn: delay);
      _retryTimer = Timer(delay, () => unawaited(restore()));
    }
  }

  /// Retries restoring the session now (the "Try again" button, or connectivity returning).
  Future<void> retry() async {
    if (state is! SessionOffline) return;
    state = const AuthUnknown();
    await restore();
  }

  /// Stores the token from a successful sign-in.
  Future<void> completeSignIn(AuthSession session) async {
    _cancelRetry();
    await ref.read(tokenStorageProvider).write(session.token);
    state = SignedIn(session.user);
  }

  /// The server rejected the stored token (401, or 403 `account_suspended`) on any request:
  /// forget it and sign out locally. Called by the API client.
  Future<void> sessionRejected() async {
    _cancelRetry();
    _failedAttempts = 0;
    await ref.read(tokenStorageProvider).clear();
    state = const SignedOut();
  }

  /// Revokes the token on the server (best effort) and forgets it locally.
  Future<void> signOut() async {
    _cancelRetry();
    try {
      await ref.read(authRepositoryProvider).logout();
    } on ApiException catch (e) {
      ref.read(loggerProvider).warning('Logout request failed: ${e.code}');
    }
    await ref.read(tokenStorageProvider).clear();
    state = const SignedOut();
  }

  void _cancelRetry() {
    _retryTimer?.cancel();
    _retryTimer = null;
  }
}
