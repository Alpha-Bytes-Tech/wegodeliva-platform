<?php

namespace App\Services\Pricing;

use App\Enums\DeliveryFeeMode;

/**
 * Delivery fee for a regular order (spec 05 §5.2). Precedence: campus fee (vendor and drop-off
 * in the same campus zone), then the fee of the vendor's zone containing the drop-off, then the
 * vendor's own flat or per-km fee, then platform defaults for vendors that follow zone fees.
 * Distance is straight-line × the road factor (owner decision 2026-11-20).
 */
final class DeliveryFeeRule
{
    /** Spread of the delivery time estimate shown to customers, in minutes. */
    private const ETA_SPREAD_MINUTES = 10;

    public function __construct(private readonly PricingSettings $settings) {}

    public function quote(DeliveryInput $in): DeliveryQuote
    {
        $roadM = intdiv($in->straightDistanceM * $this->settings->roadFactorPermille + 500, 1000);

        if (! $in->acceptsDelivery) {
            return new DeliveryQuote(false, 'pickup_only', 0, null, $roadM, null, null);
        }

        $inRange = $in->inVendorZone
            || ($in->straightDistanceM <= $in->radiusM && $in->straightDistanceM <= $this->settings->maxDeliveryDistanceM);
        if (! $inRange) {
            return new DeliveryQuote(false, 'out_of_range', 0, null, $roadM, null, null);
        }

        [$fee, $source] = match (true) {
            $in->campusFeeKobo !== null => [$in->campusFeeKobo, 'campus'],
            $in->vendorZoneFeeKobo !== null => [$in->vendorZoneFeeKobo, 'zone'],
            $in->mode === DeliveryFeeMode::Flat => [$in->vendorBaseFeeKobo + $in->vendorFeeKobo, 'vendor'],
            $in->mode === DeliveryFeeMode::PerKm => [$in->vendorBaseFeeKobo + $this->perKm($in->vendorFeeKobo, $roadM), 'vendor'],
            default => [$this->settings->defaultBaseDeliveryFeeKobo + $this->perKm($this->settings->defaultPerKmFeeKobo, $roadM), 'platform'],
        };

        $metresPerMinute = max(1, intdiv($this->settings->averageSpeedKmh * 1000, 60));
        $eta = $in->prepTimeMinutes + intdiv($roadM + $metresPerMinute - 1, $metresPerMinute);

        return new DeliveryQuote(true, null, $fee, $source, $roadM, $eta, $eta + self::ETA_SPREAD_MINUTES);
    }

    /** Fee per km × distance, rounded half-up to the kobo. */
    private function perKm(int $feePerKmKobo, int $metres): int
    {
        return intdiv($feePerKmKobo * $metres + 500, 1000);
    }
}
