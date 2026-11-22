<?php

namespace App\Support;

/** Short codes people read aloud: order codes and handover codes. */
final class OrderCodes
{
    // No 0/O, 1/I/L: easy to read over the phone.
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Eight characters from a 31-letter alphabet: about 8.5 × 10^11 codes, so not guessable. */
    public static function orderCode(): string
    {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /** The four-digit code the customer gives the rider (or vendor, for pickup) at handover. */
    public static function handoverCode(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}
