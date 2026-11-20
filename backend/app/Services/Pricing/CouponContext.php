<?php

namespace App\Services\Pricing;

use Illuminate\Support\Carbon;

/** What a coupon is checked against: the order being priced and the customer's history. */
final readonly class CouponContext
{
    /** @param  list<PricedLine>  $lines */
    public function __construct(
        public int $vendorId,
        public int $vendorTypeId,
        public array $lines,
        public ?int $deliveryFeeKobo,
        public bool $isFirstOrder,
        public int $usesTotal,
        public int $usesByCustomer,
        public Carbon $now,
    ) {}

    public function subtotalKobo(): int
    {
        return array_sum(array_map(fn (PricedLine $l) => $l->lineTotalKobo, $this->lines));
    }
}
