<?php

namespace App\Services\Orders;

use App\Enums\PaymentMethod;
use App\Models\User;
use App\Services\Ledger\WalletLedger;

/** Which payment methods an order can use, and why not when it cannot (spec 06 §6.1). */
final class PaymentOptions
{
    public function __construct(private readonly WalletLedger $wallets) {}

    /**
     * @param  bool  $recipientPays  a parcel the recipient pays for can only be paid on delivery
     * @return array<string, string|null> method => null if available, else why not
     */
    public function for(User $customer, int $total, bool $recipientPays = false): array
    {
        $cfg = config('wegodeliva.payments');
        $options = [];
        foreach (PaymentMethod::cases() as $method) {
            $options[$method->value] = match (true) {
                ! $cfg[$method->value] => 'disabled',
                $recipientPays && $method !== PaymentMethod::PayOnDelivery => 'recipient_pays',
                $method === PaymentMethod::Wallet && $this->wallets->wallet($customer)->balance_kobo < $total => 'insufficient_balance',
                $method === PaymentMethod::PayOnDelivery && $total > (int) $cfg['pay_on_delivery_max_kobo'] => 'above_limit',
                default => null,
            };
        }

        return $options;
    }
}
