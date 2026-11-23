<?php

namespace App\Events;

/** A payout was approved, sent, paid, failed or rejected. */
final class PayoutUpdated
{
    public function __construct(public readonly int $payoutId) {}
}
