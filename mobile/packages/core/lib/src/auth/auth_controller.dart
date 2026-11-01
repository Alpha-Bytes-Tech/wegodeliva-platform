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

class SignedOut extends AuthState {
  const SignedOut();
}

class SignedIn extends AuthState {
  const SignedIn(this.user);

  final AuthUser user;
}

class AuthController extends Notifier<AuthState> {
  @override
  AuthState build() => const AuthUnknown();

  /// Called by the splash screen: restores a stored session if the token is still valid.
  Future<void> restore() async {
    final storage = ref.read(tokenStorageProvider);
    final token = await storage.read();
    if (token == null) {
      state = const SignedOut();
      return;
    }
    try {
      state = SignedIn(await ref.read(authRepositoryProvider).me());
    } on ApiException catch (e) {
      if (e.statusCode == 401 || e.statusCode == 403) {
        await storage.clear();
        state = const SignedOut();
      } else {
        // Offline or server trouble: keep the token, show signed-out UI until it works.
        ref.read(loggerProvider).warning('Session restore failed: ${e.code}');
        state = const SignedOut();
      }
    }
  }

  /// Stores the token from a successful sign-in.
  Future<void> completeSignIn(AuthSession session) async {
    await ref.read(tokenStorageProvider).write(session.token);
    state = SignedIn(session.user);
  }

  /// Revokes the token on the server (best effort) and forgets it locally.
  Future<void> signOut() async {
    try {
      await ref.read(authRepositoryProvider).logout();
    } on ApiException catch (e) {
      ref.read(loggerProvider).warning('Logout request failed: ${e.code}');
    }
    await ref.read(tokenStorageProvider).clear();
    state = const SignedOut();
  }
}
