<?php

use App\Enums\DeliveryFeeMode;
use App\Services\Pricing\DeliveryFeeRule;
use App\Services\Pricing\DeliveryInput;
use App\Services\Pricing\PricingSettings;

/** @param  array<string, mixed>  $overrides */
function deliveryInput(array $overrides = []): DeliveryInput
{
    return new DeliveryInput(...array_merge([
        'acceptsDelivery' => true,
        'straightDistanceM' => 2000,
        'radiusM' => 5000,
        'mode' => DeliveryFeeMode::Flat,
        'vendorFeeKobo' => 60000,
        'vendorBaseFeeKobo' => 20000,
        'inVendorZone' => false,
        'vendorZoneFeeKobo' => null,
        'campusFeeKobo' => null,
        'prepTimeMinutes' => 20,
    ], $overrides));
}

function deliveryRule(): DeliveryFeeRule
{
    return new DeliveryFeeRule(new PricingSettings);
}

it('picks the fee by precedence: campus, vendor zone, vendor, platform', function (array $overrides, int $fee, string $source) {
    $quote = deliveryRule()->quote(deliveryInput($overrides));

    expect($quote->deliverable)->toBeTrue()
        ->and($quote->reason)->toBeNull()
        ->and($quote->feeKobo)->toBe($fee)
        ->and($quote->source)->toBe($source);
})->with([
    'campus fee wins' => [['campusFeeKobo' => 30000, 'inVendorZone' => true, 'vendorZoneFeeKobo' => 70000], 30000, 'campus'],
    'free campus delivery' => [['campusFeeKobo' => 0], 0, 'campus'],
    'vendor zone fee' => [['inVendorZone' => true, 'vendorZoneFeeKobo' => 70000], 70000, 'zone'],
    'flat: base + fee' => [[], 80000, 'vendor'],
    // 2 km × 1.3 = 2.6 km; ₦250/km → ₦650, plus ₦200 base.
    'per km on road distance' => [['mode' => DeliveryFeeMode::PerKm, 'vendorFeeKobo' => 25000], 20000 + 65000, 'vendor'],
    // Platform defaults: ₦500 base + ₦100/km × 2.6 km.
    'platform defaults for zone vendors outside their zones' => [['mode' => DeliveryFeeMode::Zone], 50000 + 26000, 'platform'],
    'vendor zone without a fee falls through' => [['inVendorZone' => true, 'mode' => DeliveryFeeMode::Zone], 76000, 'platform'],
]);

it('rounds per-km fees half up to the kobo', function () {
    // 1,001 m straight → 1,301 m road (1,301.3); 333 kobo/km × 1.301 km = 433.233 → 433.
    $quote = deliveryRule()->quote(deliveryInput(['straightDistanceM' => 1001, 'mode' => DeliveryFeeMode::PerKm, 'vendorFeeKobo' => 333, 'vendorBaseFeeKobo' => 0]));

    expect($quote->distanceM)->toBe(1301)->and($quote->feeKobo)->toBe(433);
});

it('says when a vendor cannot deliver', function (array $overrides, string $reason) {
    $quote = deliveryRule()->quote(deliveryInput($overrides));

    expect($quote->deliverable)->toBeFalse()
        ->and($quote->reason)->toBe($reason)
        ->and($quote->feeKobo)->toBe(0)
        ->and($quote->source)->toBeNull()
        ->and($quote->etaMinMinutes)->toBeNull();
})->with([
    'pickup only' => [['acceptsDelivery' => false], 'pickup_only'],
    'outside the radius' => [['straightDistanceM' => 5001], 'out_of_range'],
    'beyond the platform maximum' => [['straightDistanceM' => 20001, 'radiusM' => 50000], 'out_of_range'],
]);

it('delivers anywhere in the vendor\'s zones, whatever the radius', function () {
    $quote = deliveryRule()->quote(deliveryInput(['straightDistanceM' => 30000, 'inVendorZone' => true, 'vendorZoneFeeKobo' => 150000]));

    expect($quote->deliverable)->toBeTrue()->and($quote->feeKobo)->toBe(150000);
});

it('estimates delivery time from prep time and road distance', function () {
    // 2,600 m road at 20 km/h (333 m/min) → 8 minutes after 20 minutes' prep.
    $quote = deliveryRule()->quote(deliveryInput());

    expect($quote->etaMinMinutes)->toBe(28)->and($quote->etaMaxMinutes)->toBe(38);
});

it('reads its settings from config', function () {
    config(['wegodeliva.pricing.vat_rate_bp' => 800, 'wegodeliva.pricing.campus_mode' => false, 'wegodeliva.pricing.road_factor_permille' => 1000]);

    $settings = PricingSettings::fromConfig();

    expect($settings->vatRateBp)->toBe(800)
        ->and($settings->campusMode)->toBeFalse()
        ->and($settings->roadFactorPermille)->toBe(1000)
        ->and(app(PricingSettings::class)->vatRateBp)->toBe(800);
});
