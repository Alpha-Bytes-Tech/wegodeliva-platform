<?php

namespace App\Services\Pricing;

use App\Models\Vendor;
use App\Models\Zone;
use App\Services\Catalogue\ZoneLocator;
use App\Support\Geo;

/** Looks up the zones and distance for a vendor and drop-off point, then applies DeliveryFeeRule. */
final class DeliveryQuoter
{
    public function __construct(
        private readonly ZoneLocator $zones,
        private readonly DeliveryFeeRule $rule,
        private readonly PricingSettings $settings,
    ) {}

    public function quote(Vendor $vendor, float $lat, float $lng): DeliveryQuote
    {
        $dropZone = $this->zones->zoneAt($lat, $lng);

        $vendorZone = $dropZone === null ? null : $vendor->zones()->whereKey($dropZone->id)->first();
        $zoneFee = $vendorZone === null ? null : $this->zoneFee($vendorZone);

        $campusFee = $this->settings->campusMode && $dropZone !== null && $dropZone->is_campus && $vendor->zone_id === $dropZone->id
            ? $dropZone->default_delivery_fee_kobo
            : null;

        return $this->rule->quote(new DeliveryInput(
            acceptsDelivery: $vendor->accepts_delivery,
            straightDistanceM: Geo::distanceMeters((float) $vendor->lat, (float) $vendor->lng, $lat, $lng),
            radiusM: $vendor->delivery_radius_m,
            mode: $vendor->delivery_fee_mode,
            vendorFeeKobo: $vendor->delivery_fee_kobo,
            vendorBaseFeeKobo: $vendor->base_delivery_fee_kobo,
            inVendorZone: $vendorZone !== null,
            vendorZoneFeeKobo: $zoneFee,
            campusFeeKobo: $campusFee,
            prepTimeMinutes: $vendor->prep_time_minutes,
        ));
    }

    private function zoneFee(Zone $zone): ?int
    {
        $override = $zone->getRelation('pivot')?->getAttribute('delivery_fee_kobo');

        return $override !== null ? (int) $override : $zone->default_delivery_fee_kobo;
    }
}
