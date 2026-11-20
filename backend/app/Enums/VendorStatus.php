<?php

namespace App\Enums;

/** Vendor account state (spec 03 §3.3). Only `active` vendors are shown to customers. */
enum VendorStatus: string
{
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Suspended = 'suspended';
}
