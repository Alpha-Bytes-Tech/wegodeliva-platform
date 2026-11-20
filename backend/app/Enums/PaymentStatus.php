<?php

namespace App\Enums;

/** Payment status, separate from the order status (spec 04 §4.3). Never set by clients. */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
}
