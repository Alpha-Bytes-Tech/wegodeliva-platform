<?php

namespace App\Enums;

/** A user's role within one vendor (spec 02 §2.1). */
enum VendorMemberRole: string
{
    case Owner = 'owner';
    case Staff = 'staff';
}
