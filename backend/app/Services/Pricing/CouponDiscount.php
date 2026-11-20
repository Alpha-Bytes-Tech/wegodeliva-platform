<?php

namespace App\Services\Pricing;

use App\Enums\Party;
use App\Models\Coupon;

/** The result of applying a valid coupon. */
final readonly class CouponDiscount
{
    /**
     * @param  array<int, int>  $lineDiscounts  subtotal discount per line index (sums to $discountKobo)
     */
    public function __construct(
        public Coupon $coupon,
        public Party $fundedBy,
        public int $discountKobo,
        public int $deliveryDiscountKobo,
        public array $lineDiscounts,
    ) {}

    public function lineDiscount(int $index): int
    {
        return $this->lineDiscounts[$index] ?? 0;
    }
}
