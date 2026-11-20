<?php

namespace App\Services\Pricing;

/**
 * How an order's money divides between the vendor, whoever delivered it and WeGoDeliva.
 * vendor net + delivery net + platform revenue + VAT on commission = order total, to the kobo.
 */
final readonly class CommissionSplit
{
    public function __construct(
        public EarningsShare $vendor,
        public EarningsShare $delivery,
        // Who receives the delivery share: an independent or managed rider, or the vendor when
        // its own riders delivered (spec 05 §5.8 "Vendor-owned riders").
        public bool $deliveryToVendor,
        public int $vatCollectedForVendorKobo,
        public int $platformRevenueKobo,
        public int $commissionVatKobo,
    ) {}
}
