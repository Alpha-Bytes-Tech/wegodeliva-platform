<?php

namespace App\Events;

/** The gateway reported a checkout as failed. */
final class PaymentFailed
{
    public function __construct(public readonly int $paymentId) {}
}
