/// Push notifications via Firebase Cloud Messaging (FCM only; CLAUDE.md §2).
///
/// Phase 1 ships a no-op implementation: Firebase config files are never committed, and the
/// FCM wiring is added once the team has access to the Firebase project (see mobile/README.md).
abstract interface class PushService {
  /// The device's FCM token, or null when push is not available.
  Future<String?> deviceToken();
}

class NoopPushService implements PushService {
  const NoopPushService();

  @override
  Future<String?> deviceToken() async => null;
}
