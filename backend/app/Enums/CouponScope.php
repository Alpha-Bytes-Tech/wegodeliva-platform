<?php

namespace App\Enums;

enum CouponScope: string
{
    case All = 'all';
    case VendorType = 'vendor_type';
    case Vendors = 'vendors';
    case Products = 'products';
}
