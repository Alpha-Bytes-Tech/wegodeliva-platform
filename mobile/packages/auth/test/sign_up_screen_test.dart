import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:wegodeliva_auth/wegodeliva_auth.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

import 'helpers.dart';

void main() {
  late MockAuthRepository repository;
  const timing = OtpTiming(expiresIn: 600, resendIn: 60);

  setUp(() => repository = MockAuthRepository());

  Future<ProviderContainer> pumpSignUp(
    WidgetTester tester, {
    String? referral,
    void Function(String, OtpTiming)? onCodeSent,
    VoidCallback? onTerms,
  }) async {
    final container = ProviderContainer(
      overrides: [authRepositoryProvider.overrideWithValue(repository)],
    );
    addTearDown(container.dispose);
    container.read(pendingReferralProvider.notifier).set(referral);
    await tester.pumpWidget(
      wrap(
        SignUpScreen(
          onCodeSent: onCodeSent ?? (_, _) {},
          onSignIn: () {},
          onOpenTerms: onTerms,
        ),
        overrides: const [],
        container: container,
      ),
    );
    return container;
  }

  Future<void> tapText(WidgetTester tester, String text) async {
    final target = text == 'Create account'
        ? find.byType(FilledButton)
        : find.text(text);
    await tester.ensureVisible(target);
    await tester.pumpAndSettle();
    await tester.tap(target);
  }

  Future<void> fill(
    WidgetTester tester, {
    String phone = '0803 123 4567',
  }) async {
    await tester.enterText(
      find.widgetWithText(TextFormField, 'Full name'),
      'Ada Obi',
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'Email address'),
      ' ada@example.com ',
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'Phone number'),
      phone,
    );
  }

  testWidgets(
    'checks the form, needs the terms, then signs up and moves to the code',
    (tester) async {
      when(
        () => repository.register(
          name: any(named: 'name'),
          email: any(named: 'email'),
          phone: any(named: 'phone'),
          referralCode: any(named: 'referralCode'),
        ),
      ).thenAnswer(
        (_) async => const SignUpResult(timing: timing, referralApplied: true),
      );
      String? sentTo;
      var termsOpened = 0;
      final container = await pumpSignUp(
        tester,
        referral: 'ada12',
        onCodeSent: (email, _) => sentTo = email,
        onTerms: () => termsOpened++,
      );

      expect(find.text('ADA12'), findsOneWidget);
      await tapText(tester, 'Create account');
      await tester.pump();
      expect(find.text('Enter your name.'), findsOneWidget);
      expect(find.text('Enter a valid email address.'), findsOneWidget);

      await fill(tester, phone: '12345');
      await tapText(tester, 'Create account');
      await tester.pump();
      expect(find.textContaining('Nigerian mobile'), findsOneWidget);

      await fill(tester);
      await tapText(tester, 'Create account');
      await tester.pump();
      expect(find.text('Please accept the terms to continue.'), findsOneWidget);

      await tapText(tester, 'Read the terms');
      expect(termsOpened, 1);
      await tester.ensureVisible(find.byType(Checkbox));
      await tester.tap(find.byType(Checkbox));
      await tapText(tester, 'Create account');
      await tester.pumpAndSettle();

      expect(sentTo, 'ada@example.com');
      verify(
        () => repository.register(
          name: 'Ada Obi',
          email: 'ada@example.com',
          phone: '08031234567',
          referralCode: 'ADA12',
        ),
      ).called(1);
      expect(container.read(pendingReferralProvider), isNull);
    },
  );

  testWidgets('shows the server\'s field errors', (tester) async {
    when(
      () => repository.register(
        name: any(named: 'name'),
        email: any(named: 'email'),
        phone: any(named: 'phone'),
        referralCode: any(named: 'referralCode'),
      ),
    ).thenThrow(
      const ApiException(
        ApiError(
          code: 'validation_failed',
          message: 'Some fields need your attention.',
          fields: {
            'email': ['The email has already been taken.'],
          },
        ),
        statusCode: 400,
      ),
    );
    await pumpSignUp(tester);
    await fill(tester);
    await tester.ensureVisible(find.byType(Checkbox));
    await tester.tap(find.byType(Checkbox));
    await tapText(tester, 'Create account');
    await tester.pumpAndSettle();

    expect(find.text('The email has already been taken.'), findsOneWidget);
    expect(
      find.textContaining('Some fields need your attention.'),
      findsOneWidget,
    );
  });

  test('keeps a referral code only when there is one', () {
    final container = ProviderContainer();
    addTearDown(container.dispose);
    container.read(pendingReferralProvider.notifier).set('  ');
    expect(container.read(pendingReferralProvider), isNull);
    container.read(pendingReferralProvider.notifier).set(' ab9 ');
    expect(container.read(pendingReferralProvider), 'AB9');
  });
}
