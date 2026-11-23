<?php

namespace App\Services\Pricing;

use App\Models\ParcelRate;
use App\Support\Geo;

/**
 * Parcel price for an operator's rate (spec 05 §5.3):
 * distance part = base + (rate × route km, or a flat rate); size part = rate × kg, or a flat rate;
 * extra stops = fee × (stops − 2). The route is the sum of the legs between consecutive stops,
 * each straight-line × the road factor, like delivery fees (owner decision 2026-11-20).
 * There is no VAT on parcels: the price is a delivery charge (owner decision 2026-11-23).
 */
final class ParcelPricer
{
    public function __construct(private readonly PricingSettings $settings) {}

    /** @param  list<array{lat: float, lng: float}>  $stops  pickup first, at least two */
    public function price(ParcelRate $rate, array $stops, ?int $weightG): ParcelPrice
    {
        $straightM = 0;
        for ($i = 1, $n = count($stops); $i < $n; $i++) {
            $straightM += Geo::distanceMeters($stops[$i - 1]['lat'], $stops[$i - 1]['lng'], $stops[$i]['lat'], $stops[$i]['lng']);
        }
        $routeM = intdiv($straightM * $this->settings->roadFactorPermille + 500, 1000);

        $distance = $rate->base_price_kobo + ($rate->distance_per_km ? $this->perThousand($rate->distance_rate_kobo, $routeM) : $rate->distance_rate_kobo);
        $size = $rate->size_per_kg ? $this->perThousand($rate->size_rate_kobo, (int) $weightG) : $rate->size_rate_kobo;
        $extraStops = max(0, count($stops) - 2);
        $extraStopsKobo = $rate->extra_stop_fee_kobo * $extraStops;

        return new ParcelPrice($routeM, $distance, $size, $extraStops, $extraStopsKobo, $distance + $size + $extraStopsKobo);
    }

    /** Rate per km (per 1000 m) or per kg (per 1000 g), rounded half-up to the kobo. */
    private function perThousand(int $rateKobo, int $units): int
    {
        return intdiv($rateKobo * $units + 500, 1000);
    }
}
