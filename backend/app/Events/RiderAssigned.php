<?php

namespace App\Events;

/** A rider was assigned to an order. */
final class RiderAssigned
{
    public function __construct(public readonly int $orderId) {}
}
