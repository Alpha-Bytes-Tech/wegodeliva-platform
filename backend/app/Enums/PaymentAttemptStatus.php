<?php

namespace App\Enums;

/** Status of one gateway checkout attempt (spec 03 §3.6 `payments.status`). */
enum PaymentAttemptStatus: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Abandoned = 'abandoned';

    public function isOpen(): bool
    {
        return $this === self::Initiated || $this === self::Pending;
    }
}
