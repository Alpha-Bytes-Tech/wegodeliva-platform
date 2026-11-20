<?php

namespace App\Services\Pricing;

use App\Enums\Party;

/** A service fee as charged on one order (stored as order_fees in milestone 2.4). */
final readonly class FeeCharge
{
    public function __construct(
        public int $feeId,
        public string $name,
        public int $amountKobo,
        public Party $beneficiary,
    ) {}
}
