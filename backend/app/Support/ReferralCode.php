<?php

namespace App\Support;

use App\Models\User;
use RuntimeException;

/** Short, human-friendly referral codes without look-alike characters (0/O, 1/I/L). */
final class ReferralCode
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LENGTH = 8;

    public static function generate(): string
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    public static function generateUnique(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = self::generate();
            if (! User::withTrashed()->where('referral_code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Could not generate a unique referral code.');
    }
}
