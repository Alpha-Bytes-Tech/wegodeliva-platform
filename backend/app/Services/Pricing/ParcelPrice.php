<?php

namespace App\Services\Pricing;

use App\Support\Money;

/** A parcel price (spec 05 §5.3). The subtotal is what the customer pays and commission applies to. */
final readonly class ParcelPrice
{
    public function __construct(
        public int $routeM,
        public int $distanceKobo,
        public int $sizeKobo,
        public int $extraStops,
        public int $extraStopsKobo,
        public int $subtotalKobo,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'route_m' => $this->routeM,
            'distance_part' => Money::of($this->distanceKobo),
            'size_part' => Money::of($this->sizeKobo),
            'extra_stops' => $this->extraStops,
            'extra_stops_fee' => Money::of($this->extraStopsKobo),
            'subtotal' => Money::of($this->subtotalKobo),
        ];
    }
}
