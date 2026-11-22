<?php

namespace App\Enums;

/** Who moved an order to a new status (spec 04 §4.2 transition table). */
enum ActorRole: string
{
    case Customer = 'customer';
    case Vendor = 'vendor';
    case Rider = 'rider';
    case System = 'system';
    case Admin = 'admin';
}
