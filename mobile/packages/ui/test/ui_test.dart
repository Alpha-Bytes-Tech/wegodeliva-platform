import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

double _luminance(Color c) => c.computeLuminance();

double contrast(Color a, Color b) {
  final l1 = math.max(_luminance(a), _luminance(b));
  final l2 = math.min(_luminance(a), _luminance(b));
  return (l1 + 0.05) / (l2 + 0.05);
}

Widget host(Widget child, {ThemeData? theme}) => MaterialApp(
  theme: theme ?? WgdTheme.light(),
  home: Scaffold(body: Center(child: child)),
);

void main() {
  group('theme', () {
    for (final entry in {'light': WgdTheme.light(), 'dark': WgdTheme.dark()}.entries) {
      test('${entry.key}: brand colours with navy text on orange', () {
        final scheme = entry.value.colorScheme;
        expect(entry.value.useMaterial3, isTrue);
        expect(scheme.primary, WgdColors.orange);
        expect(scheme.onPrimary, WgdColors.navy);
        expect(contrast(scheme.primary, scheme.onPrimary), greaterThanOrEqualTo(4.5));
        expect(contrast(scheme.surface, scheme.onSurface), greaterThanOrEqualTo(4.5));
      });
    }

    test('white on orange fails contrast (why the rule exists)', () {
      expect(contrast(WgdColors.orange, Colors.white), lessThan(4.5));
    });
  });

  testWidgets('primary button is at least 48dp tall and labelled', (tester) async {
    var taps = 0;
    await tester.pumpWidget(host(WgdPrimaryButton(label: 'Continue', onPressed: () => taps++)));

    expect(tester.getSize(find.byType(FilledButton)).height, greaterThanOrEqualTo(48));
    expect(find.bySemanticsLabel('Continue'), findsOneWidget);
    await tester.tap(find.byType(FilledButton));
    expect(taps, 1);
    await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
    await expectLater(tester, meetsGuideline(textContrastGuideline));
  });

  testWidgets('busy button is disabled and announces the busy label', (tester) async {
    var taps = 0;
    await tester.pumpWidget(
      host(
        WgdPrimaryButton(
          label: 'Continue',
          busyLabel: 'Sending code',
          busy: true,
          onPressed: () => taps++,
        ),
      ),
    );

    expect(find.byType(CircularProgressIndicator), findsOneWidget);
    expect(find.bySemanticsLabel('Sending code'), findsOneWidget);
    await tester.tap(find.byType(FilledButton), warnIfMissed: false);
    expect(taps, 0);
  });

  testWidgets('wordmark and status messages have semantic labels', (tester) async {
    await tester.pumpWidget(
      host(
        const Column(
          children: [
            WgdBrandWordmark(subtitle: 'Rider'),
            WgdStatusMessage.error('That code is not correct.'),
            WgdStatusMessage.info('Check your spam folder.'),
          ],
        ),
        theme: WgdTheme.dark(),
      ),
    );

    expect(find.bySemanticsLabel('WeGoDeliva Rider'), findsOneWidget);
    expect(find.bySemanticsLabel('Error: That code is not correct.'), findsOneWidget);
    expect(find.bySemanticsLabel('Information: Check your spam folder.'), findsOneWidget);
    expect(find.text('That code is not correct.'), findsOneWidget);
  });
}
