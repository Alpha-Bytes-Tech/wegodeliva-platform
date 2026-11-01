<?php

namespace App\Enums;

/** Role names from docs/spec/02-roles-and-permissions.md §2.1. */
enum RoleName: string
{
    case Customer = 'customer';
    case VendorOwner = 'vendor_owner';
    case VendorStaff = 'vendor_staff';
    case Rider = 'rider';
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';
    case ZoneAdmin = 'zone_admin';

    /** @return list<self> */
    public static function adminRoles(): array
    {
        return [self::Admin, self::SuperAdmin, self::ZoneAdmin];
    }
}
