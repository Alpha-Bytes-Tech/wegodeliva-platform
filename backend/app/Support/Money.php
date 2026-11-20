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

    /**
     * Basis points of an amount, rounded half-up to the kobo (spec 05: every computed component
     * is rounded where it is computed). 750 bp = 7.5%.
     */
    public static function percentOf(int $kobo, int $basisPoints): int
    {
        if ($kobo < 0 || $basisPoints < 0) {
            throw new \InvalidArgumentException('Amount and rate must not be negative.');
        }

        return intdiv($kobo * $basisPoints + 5000, 10000);
    }

    /**
     * Splits an amount across weights so the parts add up exactly (largest remainder; ties go
     * to the earlier weight). Used to spread one discount over several lines.
     *
     * @param  list<int>  $weights  non-negative
     * @return list<int>
     */
    public static function allocate(int $kobo, array $weights): array
    {
        $sum = array_sum($weights);
        if ($kobo < 0 || $sum <= 0 || min($weights) < 0) {
            throw new \InvalidArgumentException('Cannot allocate a negative amount or over zero weights.');
        }

        $parts = [];
        $remainders = [];
        foreach ($weights as $i => $weight) {
            $parts[$i] = intdiv($kobo * $weight, $sum);
            $remainders[$i] = ($kobo * $weight) % $sum;
        }

        $left = $kobo - array_sum($parts);
        arsort($remainders, SORT_NUMERIC);
        // arsort keeps the original order for equal remainders, so ties go to earlier lines.
        foreach (array_keys($remainders) as $i) {
            if ($left === 0) {
                break;
            }
            $parts[$i]++;
            $left--;
        }

        return $parts;
    }
}
