<?php

namespace Tests\Support;

use App\Models\Payment;
use App\Services\Payments\Gateways\GatewayClient;
use App\Services\Payments\Gateways\GatewayUnavailable;
use App\Services\Payments\Gateways\VerifiedTransaction;
use App\Services\Payments\Gateways\WebhookNotice;
use Illuminate\Http\Request;

/** A gateway that answers what the test tells it to. */
final class FakeGateway implements GatewayClient
{
    /** @var array<string, VerifiedTransaction> */
    public array $results = [];

    public bool $down = false;

    public int $balance = 0;

    /** @var list<string> */
    public array $initialized = [];

    public function initialize(Payment $payment, string $email, string $callbackUrl): string
    {
        if ($this->down) {
            throw new GatewayUnavailable('down');
        }
        $this->initialized[] = $payment->reference;

        return "https://checkout.test/{$payment->reference}";
    }

    public function verify(string $reference): VerifiedTransaction
    {
        if ($this->down) {
            throw new GatewayUnavailable('down');
        }

        return $this->results[$reference] ?? new VerifiedTransaction(false, 'ongoing', $reference, null, null, null, null);
    }

    /** The gateway reports this payment as paid, with optional tampering. */
    public function pays(Payment $payment, ?int $amount = null, string $currency = 'NGN', ?string $reference = null, ?string $txn = null): void
    {
        $this->results[$payment->reference] = new VerifiedTransaction(true, 'success', $reference ?? $payment->reference, $amount ?? $payment->amount_kobo, $currency, $txn ?? 'txn-'.$payment->id, 'card');
    }

    public function fails(Payment $payment, string $status = 'failed'): void
    {
        $this->results[$payment->reference] = new VerifiedTransaction(false, $status, $payment->reference, null, null, null, null, final: true);
    }

    public function hasValidSignature(Request $request): bool
    {
        return true;
    }

    public function parseWebhook(array $payload): ?WebhookNotice
    {
        return null;
    }

    public function balanceKobo(): int
    {
        if ($this->down) {
            throw new GatewayUnavailable('down');
        }

        return $this->balance;
    }
}
