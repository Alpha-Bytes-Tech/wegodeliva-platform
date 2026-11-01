import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:wegodeliva_auth/wegodeliva_auth.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

import 'helpers.dart';

void main() {
  late MockAuthRepository repository;

  setUp(() => repository = MockAuthRepository());

  Future<List<String>> pump(WidgetTester tester) async {
    final sent = <String>[];
    await tester.pumpWidget(
      wrap(
        EmailLoginScreen(appSubtitle: 'Rider', onCodeSent: (email, _) => sent.add(email)),
        overrides: [authRepositoryProvider.overrideWithValue(repository)],
      ),
    );
    return sent;
  }

  testWidgets('validates the email before calling the API', (tester) async {
    final sent = await pump(tester);

    await tester.enterText(find.byType(TextFormField), 'not-an-email');
    await tester.tap(find.text('Continue'));
    await tester.pump();

    expect(find.text('Enter a valid email address.'), findsOneWidget);
    expect(sent, isEmpty);
    verifyNever(() => repository.sendOtp(any()));
  });

  testWidgets('requests a code and moves on', (tester) async {
    when(
      () => repository.sendOtp('ada@example.com'),
    ).thenAnswer((_) async => const OtpTiming(expiresIn: 600, resendIn: 60));
    final sent = await pump(tester);

    expect(find.bySemanticsLabel('WeGoDeliva Rider'), findsOneWidget);
    await tester.enterText(find.byType(TextFormField), ' ada@example.com ');
    await tester.tap(find.text('Continue'));
    await tester.pump();

    expect(sent, ['ada@example.com']);
  });

  testWidgets('shows API errors', (tester) async {
    when(() => repository.sendOtp(any())).thenThrow(const ApiException(ApiError.network));
    await pump(tester);

    await tester.enterText(find.byType(TextFormField), 'ada@example.com');
    await tester.tap(find.text('Continue'));
    await tester.pump();

    expect(find.text(ApiError.network.message), findsOneWidget);
  });
}
