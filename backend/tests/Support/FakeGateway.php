<?php

namespace Tests\Support;

use App\Models\Payment;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Services\Payments\Gateways\GatewayClient;
use App\Services\Payments\Gateways\GatewayUnavailable;
use App\Services\Payments\Gateways\TransferClient;
use App\Services\Payments\Gateways\VerifiedTransaction;
use App\Services\Payments\Gateways\WebhookNotice;
use Illuminate\Http\Request;

/** A gateway that answers what the test tells it to. */
final class FakeGateway implements GatewayClient, TransferClient
{
    /** @var array<string, VerifiedTransaction> */
    public array $results = [];

    public bool $down = false;

    public int $balance = 0;

    /** @var list<string> */
    public array $initialized = [];

    /** @var array<string, string> "bank:number" => account name */
    public array $accounts = [];

    /** @var array<string, string> transfer reference => success | failed | pending */
    public array $transfers = [];

    /** @var list<string> */
    public array $sent = [];

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

    public function banks(): array
    {
        if ($this->down) {
            throw new GatewayUnavailable('down');
        }

        return [['code' => '058', 'name' => 'GTBank'], ['code' => '044', 'name' => 'Access Bank']];
    }

    public function resolveAccount(string $bankCode, string $accountNumber): ?string
    {
        if ($this->down) {
            throw new GatewayUnavailable('down');
        }

        return $this->accounts["{$bankCode}:{$accountNumber}"] ?? null;
    }

    public function sendTransfer(Payout $payout, PayoutAccount $account): string
    {
        if ($this->down) {
            throw new GatewayUnavailable('down');
        }
        $this->sent[] = $payout->transfer_reference;

        return 'TRF_'.count($this->sent);
    }

    public function transferStatus(string $reference): string
    {
        if ($this->down) {
            throw new GatewayUnavailable('down');
        }

        return $this->transfers[$reference] ?? 'pending';
    }
}
