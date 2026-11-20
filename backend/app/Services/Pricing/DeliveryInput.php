<?php

namespace App\Services\Pricing;

use App\Enums\DeliveryFeeMode;

/** Everything the delivery fee rule needs, already looked up (see DeliveryQuoter). */
final readonly class DeliveryInput
{
    public function __construct(
        public bool $acceptsDelivery,
        public int $straightDistanceM,
        public int $radiusM,
        public DeliveryFeeMode $mode,
        public int $vendorFeeKobo,
        public int $vendorBaseFeeKobo,
        // The drop-off is inside one of the zones the vendor serves.
        public bool $inVendorZone,
        // That zone's fee: the vendor's override, else the zone default; null if neither is set.
        public ?int $vendorZoneFeeKobo,
        // Vendor and drop-off share a campus zone that has a fee (campus mode).
        public ?int $campusFeeKobo,
        public int $prepTimeMinutes = 0,
    ) {}
}
