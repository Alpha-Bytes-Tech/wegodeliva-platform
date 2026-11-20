<?php

namespace App\Enums;

enum CouponAppliesTo: string
{
    case OrderSubtotal = 'order_subtotal';
    case DeliveryFee = 'delivery_fee';
}
