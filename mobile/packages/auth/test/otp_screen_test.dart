import 'package:flutter/material.dart';
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
    storage = InMemoryTokenStorage();
    container = ProviderContainer(
      overrides: [
        authRepositoryProvider.overrideWithValue(repository),
        tokenStorageProvider.overrideWithValue(storage),
        loggerProvider.overrideWithValue(AppLogger(enabled: false)),
      ],
    );
  });
  tearDown(() => container.dispose());

  Future<void> pumpOtp(WidgetTester tester, {VoidCallback? onChangeEmail}) => tester.pumpWidget(
    wrap(
      OtpScreen(
        email: 'ada@example.com',
        initialTiming: const OtpTiming(expiresIn: 600, resendIn: 60),
        onChangeEmail: onChangeEmail,
      ),
      overrides: const [],
      container: container,
    ),
  );

  testWidgets('submits a complete 6-digit code and signs in', (tester) async {
    when(() => repository.verifyOtp('ada@example.com', '123456', deviceName: null)).thenAnswer(
      (_) async => AuthSession(
        token: '1|abc',
        expiresAt: DateTime.utc(2027),
        app: 'customer',
        user: testUser,
      ),
    );
    await pumpOtp(tester);

    expect(find.textContaining('ada@example.com'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('otp-code')), '123456');
    await tester.pump();

    verify(() => repository.verifyOtp('ada@example.com', '123456', deviceName: null)).called(1);
    expect(container.read(authControllerProvider), isA<SignedIn>());
    expect(await storage.read(), '1|abc');
  });

  testWidgets('accepts digits only, at most six, and keeps Verify disabled until complete', (
    tester,
  ) async {
    await pumpOtp(tester);

    await tester.enterText(find.byKey(const Key('otp-code')), '12a4');
    await tester.pump();

    final field = tester.widget<TextField>(find.byKey(const Key('otp-code')));
    expect(field.controller!.text, '124');
    final button = tester.widget<FilledButton>(find.byType(FilledButton));
    expect(button.onPressed, isNull);
    verifyNever(() => repository.verifyOtp(any(), any(), deviceName: any(named: 'deviceName')));
  });

  testWidgets('shows the server message for a wrong code and clears the field', (tester) async {
    when(
      () => repository.verifyOtp(any(), any(), deviceName: any(named: 'deviceName')),
    ).thenThrow(apiError('otp_invalid', 'That code is not correct.'));
    await pumpOtp(tester);

    await tester.enterText(find.byKey(const Key('otp-code')), '000000');
    await tester.pump();

    expect(find.text('That code is not correct.'), findsOneWidget);
    expect(tester.widget<TextField>(find.byKey(const Key('otp-code'))).controller!.text, isEmpty);
    expect(container.read(authControllerProvider), isNot(isA<SignedIn>()));
  });

  testWidgets('counts down before allowing a resend, then shows the spam hint', (tester) async {
    when(
      () => repository.sendOtp('ada@example.com'),
    ).thenAnswer((_) async => const OtpTiming(expiresIn: 600, resendIn: 60));
    await pumpOtp(tester);

    expect(find.text('You can request a new code in 60 s'), findsOneWidget);
    expect(find.text('Send a new code'), findsNothing);
    expect(find.textContaining('spam'), findsNothing);

    await tester.pump(const Duration(seconds: 30));
    expect(find.text('You can request a new code in 30 s'), findsOneWidget);

    await tester.pump(const Duration(seconds: 30));
    expect(find.text('Send a new code'), findsOneWidget);
    expect(find.textContaining('spam'), findsOneWidget);

    await tester.tap(find.text('Send a new code'));
    await tester.pump();
    verify(() => repository.sendOtp('ada@example.com')).called(1);
    expect(find.text('We sent you a new code.'), findsOneWidget);
    expect(find.text('You can request a new code in 60 s'), findsOneWidget);
  });

  testWidgets('respects retry_after when the server says too soon', (tester) async {
    when(() => repository.sendOtp(any())).thenThrow(
      apiError(
        'otp_resend_too_soon',
        'Please wait before requesting another code.',
        status: 429,
        meta: {'retry_after': 42},
      ),
    );
    await tester.pumpWidget(
      wrap(
        const OtpScreen(
          email: 'ada@example.com',
          initialTiming: OtpTiming(expiresIn: 600, resendIn: 0),
        ),
        overrides: const [],
        container: container,
      ),
    );

    await tester.tap(find.text('Send a new code'));
    await tester.pump();

    expect(find.text('Please wait before requesting another code.'), findsOneWidget);
    expect(find.text('You can request a new code in 42 s'), findsOneWidget);
  });

  testWidgets('offers to change the email address', (tester) async {
    var changed = 0;
    await pumpOtp(tester, onChangeEmail: () => changed++);

    await tester.tap(find.text('Use a different email'));
    await tester.tap(find.byTooltip('Back'));
    expect(changed, 2);
  });
}
