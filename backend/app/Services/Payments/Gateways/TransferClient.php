<?php

namespace App\Services\Payments\Gateways;

use App\Models\Payout;
use App\Models\PayoutAccount;

/** The gateway's Transfers API, for vendor and rider payouts (spec 06 §6.7). */
interface TransferClient
{
    /** @return list<array{code: string, name: string}> Nigerian banks */
    public function banks(): array;

    /** The account holder's name as the bank reports it, or null if the account does not exist. */
    public function resolveAccount(string $bankCode, string $accountNumber): ?string;

    /** Sends the money; returns the gateway's transfer id. */
    public function sendTransfer(Payout $payout, PayoutAccount $account): string;

    /** success | failed | pending, as the gateway reports it now. */
    public function transferStatus(string $reference): string;
}
