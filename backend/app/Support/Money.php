<?php

namespace App\Support;

/** Money in API responses: { "amount_kobo": 150000, "formatted": "₦1,500.00" } (spec 14 §14.1). */
final class Money
{
    /** @return array{amount_kobo: int, formatted: string} */
    public static function of(int $kobo): array
    {
        return ['amount_kobo' => $kobo, 'formatted' => self::format($kobo)];
    }

    public static function format(int $kobo): string
    {
        $sign = $kobo < 0 ? '-' : '';
        $abs = abs($kobo);

        return $sign.'₦'.number_format(intdiv($abs, 100)).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
