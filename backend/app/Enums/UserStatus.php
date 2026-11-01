<?php

namespace App\Enums;

/** Account states, spec 02 §2.3. */
enum UserStatus: string
{
    case Active = 'active';
    case PendingApproval = 'pending_approval';
    case Suspended = 'suspended';
    case Deleted = 'deleted';

    public function canSignIn(): bool
    {
        return $this === self::Active || $this === self::PendingApproval;
    }
}
