<?php

namespace App\Services\Pricing;

/**
 * One party's share of an order: gross earnings credited at delivery, the commission and its
 * VAT accrued against them, and what is left to withdraw (spec 05 §5.8).
 */
final readonly class EarningsShare
{
    public function __construct(
        public int $grossKobo,
        public int $commissionBaseKobo,
        public int $commissionRateBp,
        public int $commissionKobo,
        public int $commissionVatKobo,
    ) {}

    public function netKobo(): int
    {
        return $this->grossKobo - $this->commissionKobo - $this->commissionVatKobo;
    }
}
