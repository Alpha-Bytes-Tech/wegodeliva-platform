<?php

namespace App\Events;

/** Money was refunded to a customer's wallet. */
final class RefundIssued
{
    public function __construct(public readonly int $refundId) {}
}
