<?php

namespace App\Enums;

/** The figures an earnings account keeps; each ledger entry moves exactly one. */
enum EarningsBucket: string
{
    case Balance = 'balance';
    case CommissionDue = 'commission_due';
    case CommissionVatDue = 'commission_vat_due';
    case CashOwed = 'cash_owed';
    case Held = 'held';

    public function column(): string
    {
        return $this->value.'_kobo';
    }
}
