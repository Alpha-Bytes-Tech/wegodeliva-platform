import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:wegodeliva_auth/wegodeliva_auth.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

import 'helpers.dart';

void main() {
  late MockAuthRepository repository;
  late InMemoryTokenStorage storage;
  late ProviderContainer container;

  setUp(() {
    repository = MockAuthRepository();
    storage = InMemoryTokenStorage('1|token');
    container = ProviderContainer(
      overrides: [
        authRepositoryProvider.overrideWithValue(repository),
        tokenStorageProvider.overrideWithValue(storage),
        loggerProvider.overrideWithValue(AppLogger(enabled: false)),
        // No automatic retry during the test: only the button.
        sessionRetryDelayProvider.overrideWithValue(
          (_) => const Duration(hours: 1),
        ),
      ],
    );
  });
  tearDown(() => container.dispose());

  testWidgets(
    'keeps the token offline and signs in when the user taps Try again',
    (tester) async {
      var online = false;
      when(() => repository.me()).thenAnswer(
        (_) async =>
            online ? testUser : throw const ApiException(ApiError.network),
      );

      await tester.pumpWidget(
        wrap(const SplashScreen(), overrides: const [], container: container),
      );
      await tester.pumpAndSettle();

      expect(container.read(authControllerProvider), isA<SessionOffline>());
      expect(find.text("Can't connect"), findsOneWidget);
      expect(await storage.read(), '1|token');

      online = true;
      await tester.tap(find.text('Try again'));
      // The splash shows a spinner again while it retries, so pump instead of settling.
      await tester.pump();
      await tester.pump();

      expect(container.read(authControllerProvider), isA<SignedIn>());
      verify(() => repository.me()).called(2);
    },
  );
}
