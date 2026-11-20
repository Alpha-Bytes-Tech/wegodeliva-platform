<?php

namespace App\Services\Pricing;

/** Platform pricing settings (config `wegodeliva.pricing`), passed explicitly so rules stay pure. */
final readonly class PricingSettings
{
    public function __construct(
        public int $vatRateBp = 750,
        public int $commissionVatRateBp = 750,
        public int $vendorCommissionBp = 500,
        public int $deliveryCommissionBp = 300,
        public int $roadFactorPermille = 1300,
        public int $defaultBaseDeliveryFeeKobo = 50000,
        public int $defaultPerKmFeeKobo = 10000,
        public int $maxDeliveryDistanceM = 20000,
        public int $averageSpeedKmh = 20,
        public bool $campusMode = true,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, int|bool> $c */
        $c = config('wegodeliva.pricing');

        return new self(
            vatRateBp: (int) $c['vat_rate_bp'],
            commissionVatRateBp: (int) $c['commission_vat_rate_bp'],
            vendorCommissionBp: (int) $c['vendor_commission_bp'],
            deliveryCommissionBp: (int) $c['delivery_commission_bp'],
            roadFactorPermille: (int) $c['road_factor_permille'],
            defaultBaseDeliveryFeeKobo: (int) $c['default_base_delivery_fee_kobo'],
            defaultPerKmFeeKobo: (int) $c['default_per_km_fee_kobo'],
            maxDeliveryDistanceM: (int) $c['max_delivery_distance_m'],
            averageSpeedKmh: (int) $c['average_speed_kmh'],
            campusMode: (bool) $c['campus_mode'],
        );
    }
}
