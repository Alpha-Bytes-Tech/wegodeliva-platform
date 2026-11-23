<?php

namespace App\Services\Orders;

use App\Enums\PaymentStatus;
use App\Enums\WalletKind;
use App\Models\Order;
use App\Models\Referral;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Ledger\WalletLedger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Referral rewards (spec 06 §6.8): 5% of the referred customer's first purchase (subtotal less
 * discount: no delivery, fees, VAT or tip), capped at ₦5,000, credited to the referrer's wallet
 * when that order is delivered. A full refund within 7 days takes the reward back, and the
 * next delivered order counts instead.
 */
final class Referrals
{
    public function __construct(private readonly WalletLedger $wallets, private readonly AuditLogger $audit) {}

    /** Records a sign-up with someone's code. Unknown codes and self-referral are ignored. */
    public function recordSignUp(User $referred, ?string $code): ?Referral
    {
        if (! config('wegodeliva.referrals.enabled') || $code === null || $code === '') {
            return null;
        }
        $referrer = User::query()->where('referral_code', strtoupper(trim($code)))->first();
        if ($referrer === null || $referrer->id === $referred->id) {
            return null;
        }

        $referred->forceFill(['referred_by_user_id' => $referrer->id])->save();
        $referral = new Referral;
        $referral->forceFill(['referrer_user_id' => $referrer->id, 'referred_user_id' => $referred->id])->save();

        return $referral;
    }

    /** Called at delivery, inside the delivery transaction. */
    public function rewardIfFirstPurchase(Order $order): void
    {
        /** @var Referral|null $referral */
        $referral = Referral::query()->where('referred_user_id', $order->customer_id)->where('status', 'pending')->lockForUpdate()->first();
        if ($referral === null) {
            return;
        }

        $purchase = max(0, $order->subtotal_kobo - $order->discount_kobo);
        $reward = min(Money::percentOf($purchase, (int) config('wegodeliva.referrals.reward_bp')), (int) config('wegodeliva.referrals.max_reward_kobo'));
        if ($reward > 0) {
            $this->wallets->credit($referral->referrer()->firstOrFail(), $reward, WalletKind::ReferralReward, "referral:{$referral->uuid}:{$order->uuid}", [
                'order_id' => $order->id,
                'note' => 'Referral reward',
            ], enforceCap: false);
        }
        $referral->forceFill(['status' => 'rewarded', 'reward_kobo' => $reward, 'qualifying_order_id' => $order->id, 'rewarded_at' => now()])->save();
    }

    /**
     * After a refund: if it fully refunded the qualifying order within the clawback window,
     * take the reward back (as far as the wallet allows) and wait for the next order.
     */
    public function clawBackIfRefunded(Order $order): void
    {
        if ($order->payment_status !== PaymentStatus::Refunded) {
            return;
        }
        /** @var Referral|null $referral */
        $referral = Referral::query()->where('qualifying_order_id', $order->id)->where('status', 'rewarded')->lockForUpdate()->first();
        if ($referral === null || $referral->rewarded_at === null || $referral->rewarded_at->lt(now()->subDays((int) config('wegodeliva.referrals.clawback_days')))) {
            return;
        }

        $this->takeBack($referral, "Order {$order->code} refunded", "order:{$order->uuid}");
        $referral->forceFill(['status' => 'pending', 'reward_kobo' => 0, 'qualifying_order_id' => null, 'rewarded_at' => null])->save();
    }

    /** Admin decision: no reward for this referral, now or later. */
    public function void(Referral $referral, User $admin, string $reason): Referral
    {
        return DB::transaction(function () use ($referral, $admin, $reason): Referral {
            /** @var Referral $locked */
            $locked = Referral::query()->whereKey($referral->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'rewarded') {
                $this->takeBack($locked, $reason, 'void');
            }
            $locked->forceFill(['status' => 'void', 'void_reason' => $reason])->save();
            $this->audit->record($admin, 'referral.voided', 'referral', $locked->uuid, after: ['reason' => $reason]);

            return $referral->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function takeBack(Referral $referral, string $reason, string $key): void
    {
        $referrer = $referral->referrer()->firstOrFail();
        $amount = min($referral->reward_kobo, max(0, $this->wallets->wallet($referrer)->balance_kobo));
        if ($amount > 0) {
            $this->wallets->debit($referrer, $amount, WalletKind::Reversal, "referral:{$referral->uuid}:clawback:{$key}", ['note' => "Referral reward reversed: {$reason}"]);
        }
        $this->audit->record(null, 'referral.clawed_back', 'referral', $referral->uuid, after: ['reward_kobo' => $referral->reward_kobo, 'taken_kobo' => $amount, 'reason' => $reason]);
    }
}
