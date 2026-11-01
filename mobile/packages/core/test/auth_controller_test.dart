import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

class MockAuthRepository extends Mock implements AuthRepository {}

const user = AuthUser(
  uuid: 'u-1',
  name: 'Ada Obi',
  email: 'ada@example.com',
  phone: '+2348031234567',
  status: 'active',
  emailVerified: true,
  roles: ['customer'],
);

void main() {
  late MockAuthRepository repository;
  late InMemoryTokenStorage storage;

  ProviderContainer makeContainer() => ProviderContainer.test(
    overrides: [
      authRepositoryProvider.overrideWithValue(repository),
      tokenStorageProvider.overrideWithValue(storage),
      loggerProvider.overrideWithValue(AppLogger(enabled: false)),
    ],
  );

  setUp(() {
    repository = MockAuthRepository();
    storage = InMemoryTokenStorage();
  });

  test('starts unknown and becomes signed out without a stored token', () async {
    final container = makeContainer();
    expect(container.read(authControllerProvider), isA<AuthUnknown>());

    await container.read(authControllerProvider.notifier).restore();

    expect(container.read(authControllerProvider), isA<SignedOut>());
    verifyNever(() => repository.me());
  });

  test('restores a valid stored session', () async {
    await storage.write('1|token');
    when(() => repository.me()).thenAnswer((_) async => user);
    final container = makeContainer();

    await container.read(authControllerProvider.notifier).restore();

    final state = container.read(authControllerProvider);
    expect(state, isA<SignedIn>());
    expect((state as SignedIn).user.uuid, 'u-1');
  });

  test('drops a token the server no longer accepts', () async {
    await storage.write('1|expired');
    when(() => repository.me()).thenThrow(
      const ApiException(
        ApiError(code: 'unauthenticated', message: 'Please sign in.'),
        statusCode: 401,
      ),
    );
    final container = makeContainer();

    await container.read(authControllerProvider.notifier).restore();

    expect(container.read(authControllerProvider), isA<SignedOut>());
    expect(await storage.read(), isNull);
  });

  test('keeps the token when the network is down', () async {
    await storage.write('1|token');
    when(() => repository.me()).thenThrow(const ApiException(ApiError.network));
    final container = makeContainer();

    await container.read(authControllerProvider.notifier).restore();

    expect(container.read(authControllerProvider), isA<SignedOut>());
    expect(await storage.read(), '1|token');
  });

  test('stores the token on sign-in and clears it on sign-out', () async {
    when(() => repository.logout()).thenAnswer((_) async {});
    final container = makeContainer();
    final controller = container.read(authControllerProvider.notifier);

    await controller.completeSignIn(
      AuthSession(token: '2|new', expiresAt: DateTime.utc(2027), app: 'customer', user: user),
    );
    expect(await storage.read(), '2|new');
    expect(container.read(authControllerProvider), isA<SignedIn>());

    await controller.signOut();
    expect(await storage.read(), isNull);
    expect(container.read(authControllerProvider), isA<SignedOut>());
    verify(() => repository.logout()).called(1);
  });

  test('signs out locally even if the server call fails', () async {
    await storage.write('1|token');
    when(() => repository.logout()).thenThrow(const ApiException(ApiError.network));
    final container = makeContainer();

    await container.read(authControllerProvider.notifier).signOut();

    expect(await storage.read(), isNull);
    expect(container.read(authControllerProvider), isA<SignedOut>());
  });
}
