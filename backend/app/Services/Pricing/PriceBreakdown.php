<?php

namespace App\Services\Pricing;

use App\Enums\Party;
use App\Support\Money;

/**
 * The full price of an order (spec 05 §5.7):
 * total = (subtotal − discount) + (delivery fee − delivery discount) + service fees + tax + tip.
 */
final readonly class PriceBreakdown
{
    /**
     * @param  list<PricedLine>  $lines
     * @param  list<PricedLineTax>  $lineTaxes  same order as $lines
     * @param  list<FeeCharge>  $fees
     */
    public function __construct(
        public array $lines,
        public array $lineTaxes,
        public int $subtotalKobo,
        public int $discountKobo,
        public ?Party $discountFundedBy,
        public int $deliveryFeeKobo,
        public int $deliveryDiscountKobo,
        public array $fees,
        public int $serviceFeeKobo,
        public int $taxKobo,
        public int $taxRateBp,
        public int $tipKobo,
        public int $totalKobo,
        public ?CouponDiscount $coupon,
    ) {}

    public function vendorFundedDiscountKobo(): int
    {
        return $this->discountFundedBy === Party::Vendor ? $this->discountKobo : 0;
    }

    public function platformFundedDiscountKobo(): int
    {
        return ($this->discountFundedBy === Party::Platform ? $this->discountKobo : 0) + $this->deliveryDiscountKobo;
    }

    public function feesFor(Party $beneficiary): int
    {
        return array_sum(array_map(fn (FeeCharge $f) => $f->beneficiary === $beneficiary ? $f->amountKobo : 0, $this->fees));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (PricedLine $l, PricedLineTax $t) => [
                'product_id' => $l->productUuid,
                'name' => $l->name,
                'quantity' => $l->quantity,
                'unit_price' => Money::of($l->unitPriceKobo),
                'options' => array_map(fn (PricedOption $o) => $o->toArray(), $l->options),
                'line_total' => Money::of($l->lineTotalKobo),
                'vat_rate_bp' => $t->rateBp,
                'vat' => Money::of($t->amountKobo),
            ], $this->lines, $this->lineTaxes),
            'subtotal' => Money::of($this->subtotalKobo),
            'discount' => Money::of($this->discountKobo),
            'delivery_fee' => Money::of($this->deliveryFeeKobo),
            'delivery_discount' => Money::of($this->deliveryDiscountKobo),
            'fees' => array_map(fn (FeeCharge $f) => ['name' => $f->name, 'amount' => Money::of($f->amountKobo)], $this->fees),
            'service_fee' => Money::of($this->serviceFeeKobo),
            'tax' => Money::of($this->taxKobo),
            'tax_rate_bp' => $this->taxRateBp,
            'tip' => Money::of($this->tipKobo),
            'total' => Money::of($this->totalKobo),
            'coupon_code' => $this->coupon?->coupon->code,
        ];
    }
}
