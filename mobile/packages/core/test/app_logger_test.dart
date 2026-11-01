import 'package:flutter_test/flutter_test.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

void main() {
  test('redacts emails, phone numbers, tokens and codes', () {
    final line = AppLogger.redact(
      'user ada.obi@example.com phone +234 803 123 4567 sent 123456 with Bearer 12|AbCdEf',
    );

    expect(line, isNot(contains('ada.obi')));
    expect(line, isNot(contains('803')));
    expect(line, isNot(contains('123456')));
    expect(line, isNot(contains('AbCdEf')));
    expect(line, contains('[email]'));
    expect(line, contains('Bearer [token]'));
  });

  test('writes redacted lines to the sink only when enabled', () {
    final lines = <String>[];
    AppLogger(sink: (level, message) => lines.add('$level $message'), enabled: true)
      ..info('hello ada@example.com')
      ..error('failed', StateError('x'));
    AppLogger(sink: (_, message) => lines.add(message), enabled: false).info('hidden');

    expect(lines, ['INFO hello [email]', 'ERROR failed: StateError']);
  });

  test('the no-op push service has no token', () async {
    expect(await const NoopPushService().deviceToken(), isNull);
  });
}
