<?php

namespace App\Enums;

/** Why an earnings figure changed (spec 05 §5.8, spec 06 §6.5–6.7). */
enum EarningsKind: string
{
    case OrderEarning = 'order_earning';
    case CommissionAccrued = 'commission_accrued';
    case CommissionVatAccrued = 'commission_vat_accrued';
    case CommissionSettled = 'commission_settled';
    case CommissionVatSettled = 'commission_vat_settled';
    case CashCollected = 'cash_collected';
    case CashRemitted = 'cash_remitted';
    case CashOffset = 'cash_offset';
    case PayoutHold = 'payout_hold';
    case PayoutReleased = 'payout_released';
    case PayoutPaid = 'payout_paid';
    case Reversal = 'reversal';
    case Adjustment = 'adjustment';
}
