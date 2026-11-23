<?php

namespace App\Services\Orders;

use App\Enums\Fault;
use App\Enums\PaymentStatus;
use App\Enums\WalletKind;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Services\Ledger\WalletLedger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Refunds to the customer's wallet (spec 06 §6.6): never more than was paid and not yet
 * refunded, idempotent on the reference, and the order's payment status follows.
 */
final class Refunds
{
    public function __construct(private readonly WalletLedger $wallets, private readonly Referrals $referrals) {}

    public function refund(Order $order, int $amount, string $reason, Fault $fault, ?User $by, string $reference): Refund
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('A refund needs a positive amount.');
        }

        return DB::transaction(function () use ($order, $amount, $reason, $fault, $by, $reference): Refund {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->with('customer')->lockForUpdate()->firstOrFail();

            $existing = Refund::query()->where('reference', $reference)->first();
            if ($existing !== null) {
                if ($existing->order_id !== $locked->id || $existing->amount_kobo !== $amount) {
                    throw new ApiException('conflict', 409);
                }

                return $existing;
            }

            $refundable = $locked->refundableKobo();
            if ($amount > $refundable) {
                throw new ApiException('refund_exceeds_paid', 422, meta: ['refundable' => Money::of($refundable)]);
            }

            $refund = new Refund;
            $refund->forceFill([
                'order_id' => $locked->id,
                'amount_kobo' => $amount,
                'reason' => $reason,
                'fault' => $fault,
                'reference' => $reference,
                'processed_by' => $by?->id,
            ])->save();

            $this->wallets->credit($locked->customer, $amount, WalletKind::Refund, $reference, [
                'order_id' => $locked->id,
                'note' => "Refund for order {$locked->code}",
            ], enforceCap: false);

            $locked->payment_status = $amount === $refundable ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;
            $locked->save();
            $order->payment_status = $locked->payment_status;
            $this->referrals->clawBackIfRefunded($locked);

            return $refund;
        });
    }
}
