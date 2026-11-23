<?php

namespace App\Events;

/** An order moved to a new status (or was created). Carries ids only. */
final class OrderStatusChanged
{
    public function __construct(
        public readonly int $orderId,
        public readonly ?string $from,
        public readonly string $to,
        public readonly string $actor,
        public readonly ?string $reason,
    ) {}
}
