<?php

namespace App\Services\Payments\Gateways;

/** The parts of a webhook we act on. The payload itself is never trusted for amounts. */
final readonly class WebhookNotice
{
    public function __construct(
        public string $eventType,
        public string $eventKey,
        public ?string $reference,
        // A charge event: verify the payment with the gateway's API.
        public bool $isCharge,
    ) {}
}
