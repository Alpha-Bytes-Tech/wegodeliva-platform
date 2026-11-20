<?php

namespace App\Enums;

/** How a vendor charges for delivery when no zone fee applies (spec 05 §5.2). */
enum DeliveryFeeMode: string
{
    case Zone = 'zone';
    case Flat = 'flat';
    case PerKm = 'per_km';
}
