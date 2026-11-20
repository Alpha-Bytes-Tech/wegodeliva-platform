<?php

namespace App\Enums;

/** How a coupon or fee amount is expressed: basis points of a base, or a fixed kobo amount. */
enum DiscountType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
}
