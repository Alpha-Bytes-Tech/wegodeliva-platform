<?php

namespace App\Services\Pricing;

/** VAT on one line: rate 0 when the vendor is not VAT-registered or the line is not standard-rated. */
final readonly class PricedLineTax
{
    public function __construct(
        public int $rateBp,
        public int $baseKobo,
        public int $amountKobo,
    ) {}
}
