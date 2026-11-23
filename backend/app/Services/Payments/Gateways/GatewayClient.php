<?php

namespace App\Services\Payments\Gateways;

use App\Models\Payment;
use Illuminate\Http\Request;

/** A payment gateway (spec 06 §6.1–6.3). Secrets come from config, never the database. */
interface GatewayClient
{
    /** Starts a checkout and returns the URL the app opens. */
    public function initialize(Payment $payment, string $email, string $callbackUrl): string;

    /** Asks the gateway, with our secret key, what happened to the transaction. */
    public function verify(string $reference): VerifiedTransaction;

    /** Checks the webhook signature against the raw body. */
    public function hasValidSignature(Request $request): bool;

    /** @param  array<string, mixed>  $payload */
    public function parseWebhook(array $payload): ?WebhookNotice;

    /** The NGN balance held at the gateway, in kobo (for reconciliation). */
    public function balanceKobo(): int;
}
