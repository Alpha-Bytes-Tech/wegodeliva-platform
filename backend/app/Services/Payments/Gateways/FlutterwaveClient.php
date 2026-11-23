<?php

namespace App\Services\Payments\Gateways;

use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Flutterwave v3 (https://developer.flutterwave.com). Flutterwave takes and returns amounts in
 * naira, so they are converted to and from kobo here and nowhere else.
 */
final class FlutterwaveClient implements GatewayClient
{
    public function initialize(Payment $payment, string $email, string $callbackUrl): string
    {
        $data = $this->call(fn (PendingRequest $http) => $http->post('/v3/payments', [
            'tx_ref' => $payment->reference,
            'amount' => self::naira($payment->amount_kobo),
            'currency' => $payment->currency,
            'redirect_url' => $callbackUrl,
            'customer' => ['email' => $email],
            'meta' => ['payment_id' => $payment->uuid],
        ]));

        return (string) ($data['link'] ?? throw new GatewayUnavailable('Flutterwave returned no checkout link.'));
    }

    public function verify(string $reference): VerifiedTransaction
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/v3/transactions/verify_by_reference', ['tx_ref' => $reference]));
        if ($response->status() === 400 || $response->status() === 404) {
            return new VerifiedTransaction(false, 'not_found', null, null, null, null, null);
        }
        $data = $this->data($response);
        $status = (string) ($data['status'] ?? 'unknown');

        return new VerifiedTransaction(
            successful: $status === 'successful',
            status: $status,
            reference: isset($data['tx_ref']) ? (string) $data['tx_ref'] : null,
            amountKobo: isset($data['amount']) ? self::kobo($data['amount']) : null,
            currency: isset($data['currency']) ? (string) $data['currency'] : null,
            transactionId: isset($data['id']) ? (string) $data['id'] : null,
            channel: isset($data['payment_type']) ? (string) $data['payment_type'] : null,
            final: in_array($status, ['failed', 'cancelled'], true),
        );
    }

    /** `verif-hash` must equal the secret hash configured on the Flutterwave dashboard (spec 06 §6.3). */
    public function hasValidSignature(Request $request): bool
    {
        $secret = (string) config('services.flutterwave.secret_hash');
        $signature = (string) $request->header('verif-hash');

        return $secret !== '' && $signature !== '' && hash_equals($secret, $signature);
    }

    public function parseWebhook(array $payload): ?WebhookNotice
    {
        $event = $payload['event'] ?? null;
        $data = $payload['data'] ?? null;
        if (! is_string($event) || ! is_array($data) || ! isset($data['id'])) {
            return null;
        }

        return new WebhookNotice($event, $event.':'.$data['id'], isset($data['tx_ref']) ? (string) $data['tx_ref'] : null, $event === 'charge.completed');
    }

    public function balanceKobo(): int
    {
        $data = $this->call(fn (PendingRequest $http) => $http->get('/v3/balances/NGN'));

        return self::kobo($data['available_balance'] ?? 0);
    }

    /** 150000 kobo → "1500.00" naira. */
    public static function naira(int $kobo): string
    {
        return intdiv($kobo, 100).'.'.str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT);
    }

    /** 1500.5 or "1500.50" naira → 150050 kobo, without float drift. */
    public static function kobo(mixed $naira): int
    {
        $text = is_float($naira) ? number_format($naira, 2, '.', '') : (string) $naira;
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     * @return array<string, mixed>
     */
    private function call(callable $request): array
    {
        return $this->data($this->send($request));
    }

    /** @param  callable(PendingRequest): Response  $request */
    private function send(callable $request): Response
    {
        $http = Http::baseUrl((string) config('services.flutterwave.base_url'))
            ->withToken((string) config('services.flutterwave.secret_key'))
            ->acceptJson()
            ->timeout(15)
            ->connectTimeout(5);

        try {
            return $request($http);
        } catch (ConnectionException $e) {
            throw new GatewayUnavailable('Flutterwave could not be reached.', previous: $e);
        }
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        $data = $response->json('data');
        if (! $response->successful() || $response->json('status') !== 'success' || ! is_array($data)) {
            throw new GatewayUnavailable('Flutterwave answered HTTP '.$response->status().'.');
        }

        return $data;
    }
}
