<?php

namespace App\Services\Pricing;

use App\Enums\Party;
use App\Support\Money;

/**
 * Commission and earnings for a delivered order (spec 05 §5.8). Commission is never charged on
 * VAT, vendor fees or tips; WeGoDeliva charges VAT on its own commission.
 */
final class CommissionCalculator
{
    public function __construct(private readonly PricingSettings $settings) {}

    /**
     * @param  int|null  $vendorRateBp  the vendor's own rate, or null for the platform default
     * @param  int|null  $deliveryRateBp  the rider's own rate, or null for the platform default
     */
    public function split(PriceBreakdown $price, ?int $vendorRateBp = null, ?int $deliveryRateBp = null, bool $deliveryToVendor = false): CommissionSplit
    {
        $vendorRate = $vendorRateBp ?? $this->settings->vendorCommissionBp;
        $deliveryRate = $deliveryRateBp ?? $this->settings->deliveryCommissionBp;

        // A platform-funded discount does not reduce what the vendor earns.
        $goods = $price->subtotalKobo - $price->vendorFundedDiscountKobo();
        $vendor = $this->share($goods + $price->feesFor(Party::Vendor) + $price->taxKobo, $goods, $vendorRate);

        // Delivery coupons are platform-funded, so the full fee is earned; tips are never commissioned.
        $delivery = $this->share($price->deliveryFeeKobo + $price->tipKobo, $price->deliveryFeeKobo, $deliveryRate);

        $revenue = $vendor->commissionKobo + $delivery->commissionKobo
            + $price->feesFor(Party::Platform) - $price->platformFundedDiscountKobo();

        return new CommissionSplit(
            vendor: $vendor,
            delivery: $delivery,
            deliveryToVendor: $deliveryToVendor,
            vatCollectedForVendorKobo: $price->taxKobo,
            platformRevenueKobo: $revenue,
            commissionVatKobo: $vendor->commissionVatKobo + $delivery->commissionVatKobo,
        );
    }

    private function share(int $gross, int $base, int $rateBp): EarningsShare
    {
        $commission = Money::percentOf($base, $rateBp);

        return new EarningsShare($gross, $base, $rateBp, $commission, Money::percentOf($commission, $this->settings->commissionVatRateBp));
    }
}
