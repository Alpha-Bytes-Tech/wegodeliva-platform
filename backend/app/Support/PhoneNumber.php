<?php

namespace App\Support;

/**
 * Nigerian mobile numbers in E.164 (+234…). Accepts the common local formats
 * (0803…, 234803…, +234 803 …) and returns null when the number is not a Nigerian mobile.
 */
final class PhoneNumber
{
    /** Nigerian mobile ranges start 70x, 80x, 81x, 90x, 91x. */
    private const E164_PATTERN = '/^\+234[789][01]\d{8}$/';

    public static function normalise(string $input): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', trim($input)) ?? '';

        if (str_starts_with($digits, '+')) {
            $candidate = $digits;
        } elseif (str_starts_with($digits, '234')) {
            $candidate = '+'.$digits;
        } elseif (str_starts_with($digits, '0')) {
            $candidate = '+234'.substr($digits, 1);
        } else {
            return null;
        }

        return preg_match(self::E164_PATTERN, $candidate) === 1 ? $candidate : null;
    }
}
