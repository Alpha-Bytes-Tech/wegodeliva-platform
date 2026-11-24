import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

Widget host(Widget child) => MaterialApp(
  theme: WgdTheme.light(),
  home: Scaffold(body: child),
);

void main() {
  testWidgets('shows a labelled spinner while loading', (tester) async {
    await tester.pumpWidget(
      host(const WgdStateView.loading(label: 'Loading stores')),
    );
    expect(find.bySemanticsLabel('Loading stores'), findsOneWidget);
  });

  testWidgets('shows an empty state with an optional action', (tester) async {
    var tapped = 0;
    await tester.pumpWidget(
      host(
        WgdStateView.empty(
          title: 'No orders yet',
          message: 'Your orders appear here.',
          actionLabel: 'Browse',
          onAction: () => tapped++,
        ),
      ),
    );
    expect(find.text('No orders yet'), findsOneWidget);
    expect(find.text('Your orders appear here.'), findsOneWidget);
    await tester.tap(find.text('Browse'));
    expect(tapped, 1);

    await tester.pumpWidget(
      host(const WgdStateView.empty(title: 'Nothing here')),
    );
    expect(find.byType(OutlinedButton), findsNothing);
  });

  testWidgets('shows an error with a retry button', (tester) async {
    var retried = 0;
    await tester.pumpWidget(
      host(
        WgdStateView.error(
          title: 'Could not load',
          retryLabel: 'Try again',
          onRetry: () => retried++,
        ),
      ),
    );
    expect(find.byIcon(Icons.cloud_off_outlined), findsOneWidget);
    expect(find.text('Could not load'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'Try again'));
    expect(retried, 1);
  });
}
