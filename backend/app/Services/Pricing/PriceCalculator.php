<?php

namespace App\Services\Pricing;

use App\Enums\DiscountType;
use App\Enums\Party;
use App\Enums\VatTreatment;
use App\Models\Fee;
use App\Support\Money;

/**
 * Turns priced lines, the delivery fee, a coupon, service fees and a tip into the order total
 * (spec 05 §5.4–5.7). Pure: no database access, every component rounded half-up where computed.
 */
final class PriceCalculator
{
    public function __construct(private readonly PricingSettings $settings) {}

    /**
     * @param  list<PricedLine>  $lines
     * @param  int|null  $deliveryFeeKobo  null for pickup orders
     * @param  iterable<Fee>  $fees  the vendor's fees; inactive ones are skipped
     */
    public function calculate(
        array $lines,
        bool $vendorVatRegistered,
        ?int $deliveryFeeKobo,
        ?CouponDiscount $coupon,
        iterable $fees,
        int $tipKobo = 0,
    ): PriceBreakdown {
        if ($lines === []) {
            throw new \InvalidArgumentException('An order needs at least one line.');
        }
        if ($tipKobo < 0 || ($deliveryFeeKobo ?? 0) < 0) {
            throw new \InvalidArgumentException('Tip and delivery fee must not be negative.');
        }

        $subtotal = array_sum(array_map(fn (PricedLine $l) => $l->lineTotalKobo, $lines));
        $delivery = $deliveryFeeKobo ?? 0;
        $discount = $coupon === null ? 0 : $coupon->discountKobo;
        $deliveryDiscount = $coupon === null ? 0 : min($coupon->deliveryDiscountKobo, $delivery);
        $fundedBy = $coupon !== null && $discount > 0 ? $coupon->fundedBy : null;

        // VAT is due on what the customer is charged for the line: a vendor-funded discount
        // lowers the base, a platform-funded one does not (spec 05 §5.6).
        $lineTaxes = [];
        foreach ($lines as $i => $line) {
            $rate = $vendorVatRegistered && $line->vatTreatment === VatTreatment::Standard ? $this->settings->vatRateBp : 0;
            $base = $line->lineTotalKobo - ($coupon !== null && $fundedBy === Party::Vendor ? $coupon->lineDiscount($i) : 0);
            $lineTaxes[] = new PricedLineTax($rate, $base, Money::percentOf($base, $rate));
        }
        $tax = array_sum(array_map(fn (PricedLineTax $t) => $t->amountKobo, $lineTaxes));

        $charges = [];
        foreach ($fees as $fee) {
            if (! $fee->is_active) {
                continue;
            }
            $amount = $fee->type === DiscountType::Percent ? Money::percentOf($subtotal, $fee->value) : $fee->value;
            $charges[] = new FeeCharge($fee->id, $fee->name, $amount, $fee->beneficiary);
        }
        $serviceFee = array_sum(array_map(fn (FeeCharge $c) => $c->amountKobo, $charges));

        $total = ($subtotal - $discount) + ($delivery - $deliveryDiscount) + $serviceFee + $tax + $tipKobo;

        return new PriceBreakdown(
            lines: $lines,
            lineTaxes: $lineTaxes,
            subtotalKobo: $subtotal,
            discountKobo: $discount,
            discountFundedBy: $fundedBy,
            deliveryFeeKobo: $delivery,
            deliveryDiscountKobo: $deliveryDiscount,
            fees: $charges,
            serviceFeeKobo: $serviceFee,
            taxKobo: $tax,
            taxRateBp: $tax > 0 ? $this->settings->vatRateBp : 0,
            tipKobo: $tipKobo,
            totalKobo: $total,
            coupon: $coupon,
        );
    }
}
