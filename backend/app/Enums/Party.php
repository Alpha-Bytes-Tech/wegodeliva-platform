<?php

namespace App\Enums;

/** Platform or vendor: who funds a coupon or receives a service fee (spec 05 §5.4–5.5). */
enum Party: string
{
    case Platform = 'platform';
    case Vendor = 'vendor';
}
