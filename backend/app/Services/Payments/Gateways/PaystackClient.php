<?php

namespace App\Services\Payments\Gateways;

use App\Models\Payment;
use App\Models\Payout;
use App\Models\PayoutAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/** Paystack (https://paystack.com/docs/api). Amounts are in kobo on both sides. */
final class PaystackClient implements GatewayClient, TransferClient
{
    public function initialize(Payment $payment, string $email, string $callbackUrl): string
    {
        $data = $this->call(fn (PendingRequest $http) => $http->post('/transaction/initialize', [
            'email' => $email,
            'amount' => $payment->amount_kobo,
            'currency' => $payment->currency,
            'reference' => $payment->reference,
            'callback_url' => $callbackUrl,
            'metadata' => ['payment_id' => $payment->uuid],
        ]));

        return (string) ($data['authorization_url'] ?? throw new GatewayUnavailable('Paystack returned no checkout URL.'));
    }

    public function verify(string $reference): VerifiedTransaction
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/transaction/verify/'.rawurlencode($reference)));
        if ($response->status() === 400 || $response->status() === 404) {
            // Paystack has no transaction with this reference (yet): nothing was paid.
            return new VerifiedTransaction(false, 'not_found', null, null, null, null, null);
        }
        $data = $this->data($response);
        $status = (string) ($data['status'] ?? 'unknown');

        return new VerifiedTransaction(
            successful: $status === 'success',
            status: $status,
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
            amountKobo: isset($data['amount']) ? (int) $data['amount'] : null,
            currency: isset($data['currency']) ? (string) $data['currency'] : null,
            transactionId: isset($data['id']) ? (string) $data['id'] : null,
            channel: isset($data['channel']) ? (string) $data['channel'] : null,
            final: in_array($status, ['failed', 'abandoned', 'reversed'], true),
        );
    }

    /** `x-paystack-signature` is the HMAC-SHA512 of the raw body with the secret key (spec 06 §6.3). */
    public function hasValidSignature(Request $request): bool
    {
        $secret = (string) config('services.paystack.secret_key');
        $signature = (string) $request->header('x-paystack-signature');

        return $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $signature);
    }

    public function parseWebhook(array $payload): ?WebhookNotice
    {
        $event = $payload['event'] ?? null;
        $data = $payload['data'] ?? null;
        if (! is_string($event) || ! is_array($data) || ! isset($data['id'])) {
            return null;
        }

        return new WebhookNotice(
            $event,
            $event.':'.$data['id'],
            isset($data['reference']) ? (string) $data['reference'] : null,
            $event === 'charge.success',
            in_array($event, ['transfer.success', 'transfer.failed', 'transfer.reversed'], true),
        );
    }

    public function banks(): array
    {
        $data = $this->call(fn (PendingRequest $http) => $http->get('/bank', ['country' => 'nigeria', 'currency' => 'NGN', 'perPage' => 200]));

        return array_values(array_map(fn ($b) => ['code' => (string) $b['code'], 'name' => (string) $b['name']], array_filter($data, 'is_array')));
    }

    public function resolveAccount(string $bankCode, string $accountNumber): ?string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/bank/resolve', ['account_number' => $accountNumber, 'bank_code' => $bankCode]));
        if ($response->status() === 422 || $response->status() === 400) {
            return null;
        }

        $name = $this->data($response)['account_name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function sendTransfer(Payout $payout, PayoutAccount $account): string
    {
        if ($account->recipient_code === null) {
            $recipient = $this->call(fn (PendingRequest $http) => $http->post('/transferrecipient', [
                'type' => 'nuban',
                'name' => $account->account_name,
                'account_number' => $account->account_number,
                'bank_code' => $account->bank_code,
                'currency' => 'NGN',
            ]));
            $account->forceFill(['recipient_code' => (string) $recipient['recipient_code']])->save();
        }

        $data = $this->call(fn (PendingRequest $http) => $http->post('/transfer', [
            'source' => 'balance',
            'amount' => $payout->amount_kobo,
            'recipient' => $account->recipient_code,
            'reference' => $payout->transfer_reference,
            'reason' => 'WeGoDeliva payout',
        ]));

        return (string) ($data['transfer_code'] ?? $data['id'] ?? throw new GatewayUnavailable('Paystack returned no transfer id.'));
    }

    public function transferStatus(string $reference): string
    {
        $status = (string) ($this->call(fn (PendingRequest $http) => $http->get('/transfer/verify/'.rawurlencode($reference)))['status'] ?? '');

        return match ($status) {
            'success' => 'success',
            'failed', 'reversed', 'abandoned', 'rejected' => 'failed',
            default => 'pending',
        };
    }

    public function balanceKobo(): int
    {
        $data = $this->call(fn (PendingRequest $http) => $http->get('/balance'));
        foreach ((array) $data as $row) {
            if (is_array($row) && ($row['currency'] ?? null) === 'NGN') {
                return (int) $row['balance'];
            }
        }

        return 0;
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     * @return array<int|string, mixed>
     */
    private function call(callable $request): array
    {
        return $this->data($this->send($request));
    }

    /** @param  callable(PendingRequest): Response  $request */
    private function send(callable $request): Response
    {
        $http = Http::baseUrl((string) config('services.paystack.base_url'))
            ->withToken((string) config('services.paystack.secret_key'))
            ->acceptJson()
            ->timeout(15)
            ->connectTimeout(5);

        try {
            return $request($http);
        } catch (ConnectionException $e) {
            throw new GatewayUnavailable('Paystack could not be reached.', previous: $e);
        }
    }

    /** @return array<int|string, mixed> */
    private function data(Response $response): array
    {
        $data = $response->json('data');
        if (! $response->successful() || $response->json('status') !== true || ! is_array($data)) {
            throw new GatewayUnavailable('Paystack answered HTTP '.$response->status().'.');
        }

        return $data;
    }
}
