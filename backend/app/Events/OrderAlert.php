<?php

namespace App\Events;

/** A background check flagged an order (vendor_slow, no_rider). */
final class OrderAlert
{
    public function __construct(public readonly int $orderId, public readonly string $alert) {}
}
