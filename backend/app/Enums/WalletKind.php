<?php

namespace App\Enums;

/** Why a wallet balance changed (spec 03 §3.6). */
enum WalletKind: string
{
    case TopUp = 'topup';
    case OrderPayment = 'order_payment';
    case Refund = 'refund';
    case ReferralReward = 'referral_reward';
    case AdminAdjustment = 'admin_adjustment';
    // Taking back money credited in error, e.g. a referral reward on a refunded order.
    case Reversal = 'reversal';

    public function isCredit(): bool
    {
        return in_array($this, [self::TopUp, self::Refund, self::ReferralReward], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::TopUp => 'Top-up',
            self::OrderPayment => 'Order payment',
            self::Refund => 'Refund',
            self::ReferralReward => 'Referral reward',
            self::AdminAdjustment => 'Adjustment',
            self::Reversal => 'Reversal',
        };
    }
}
