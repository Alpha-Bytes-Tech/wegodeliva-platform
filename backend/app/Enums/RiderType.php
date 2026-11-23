<?php

namespace App\Enums;

/** The three kinds of rider (spec 07 §7.6). */
enum RiderType: string
{
    case Managed = 'wegodeliva_managed';
    case Independent = 'independent';
    case VendorOwned = 'vendor_owned';
}
