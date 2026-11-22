<?php

namespace App\Enums;

/** Who is at fault when an order is cancelled or fails (spec 06 §6.6). */
enum Fault: string
{
    case Customer = 'customer';
    case Vendor = 'vendor';
    case Rider = 'rider';
    case Platform = 'platform';
    case None = 'none';
}
