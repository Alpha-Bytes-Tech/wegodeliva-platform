<?php

namespace App\Services\Payments\Gateways;

/** What the gateway's verify endpoint says about a transaction, in our terms. */
final readonly class VerifiedTransaction
{
    public function __construct(
        public bool $successful,
        // The gateway's own status word, for the record (e.g. "success", "abandoned").
        public string $status,
        public ?string $reference,
        public ?int $amountKobo,
        public ?string $currency,
        public ?string $transactionId,
        public ?string $channel,
        // Definitely not paid and never will be (failed/abandoned), as opposed to still pending.
        public bool $final = false,
    ) {}

    /** @return array<string, mixed> the non-sensitive fields we keep */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'reference' => $this->reference,
            'amount_kobo' => $this->amountKobo,
            'currency' => $this->currency,
            'transaction_id' => $this->transactionId,
            'channel' => $this->channel,
        ];
    }
}
