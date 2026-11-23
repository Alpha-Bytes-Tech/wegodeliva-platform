<?php

use App\Models\ParcelRate;
use App\Services\Pricing\ParcelPricer;
use App\Services\Pricing\PricingSettings;

/** Two points 1,112 m apart (straight line), so 1,446 m by road at the 1.3 factor. */
const PARCEL_A = ['lat' => 6.520, 'lng' => 3.385];
const PARCEL_B = ['lat' => 6.530, 'lng' => 3.385];
const PARCEL_C = ['lat' => 6.540, 'lng' => 3.385];

function parcelRate(array $attributes): ParcelRate
{
    return (new ParcelRate)->forceFill(array_merge([
        'base_price_kobo' => 100000, 'distance_rate_kobo' => 20000, 'distance_per_km' => true,
        'size_rate_kobo' => 0, 'size_per_kg' => false, 'extra_stop_fee_kobo' => 30000,
    ], $attributes));
}

it('charges base plus a per-km rate on the road route, and a flat size rate', function () {
    $price = (new ParcelPricer(new PricingSettings))->price(parcelRate(['size_rate_kobo' => 5000]), [PARCEL_A, PARCEL_B], null);

    expect($price->routeM)->toBe(1446)
        ->and($price->distanceKobo)->toBe(100000 + 28920)
        ->and($price->sizeKobo)->toBe(5000)
        ->and($price->extraStops)->toBe(0)
        ->and($price->extraStopsKobo)->toBe(0)
        ->and($price->subtotalKobo)->toBe(133920)
        ->and($price->toArray()['subtotal']['amount_kobo'])->toBe(133920);
});

it('sums every leg, charges each stop beyond the second, and prices size per kg', function () {
    $rate = parcelRate(['size_rate_kobo' => 10000, 'size_per_kg' => true]);
    $price = (new ParcelPricer(new PricingSettings))->price($rate, [PARCEL_A, PARCEL_B, PARCEL_C, PARCEL_A], 2500);

    // Legs 1,112 + 1,112 + 2,224 m straight = 4,448 m → 5,782 m by road.
    expect($price->routeM)->toBe(5782)
        ->and($price->distanceKobo)->toBe(100000 + 115640)
        ->and($price->sizeKobo)->toBe(25000)
        ->and($price->extraStops)->toBe(2)
        ->and($price->extraStopsKobo)->toBe(60000)
        ->and($price->subtotalKobo)->toBe(100000 + 115640 + 25000 + 60000);
});

it('uses a flat distance rate when the operator does not price per km', function () {
    $price = (new ParcelPricer(new PricingSettings))->price(parcelRate(['distance_per_km' => false, 'distance_rate_kobo' => 50000]), [PARCEL_A, PARCEL_C], null);

    expect($price->distanceKobo)->toBe(150000)->and($price->subtotalKobo)->toBe(150000);
});
