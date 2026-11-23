<?php

namespace App\Services\Orders;

use App\Models\Product;
use Illuminate\Support\Carbon;

/** The booking part of a built quote: which service, for how long, when and where. */
final readonly class BookingQuote
{
    public function __construct(
        public Product $service,
        public int $units,
        public Carbon $startsAt,
        public Carbon $endsAt,
        public string $location,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'service' => ['id' => $this->service->uuid, 'name' => $this->service->name, 'pricing_unit' => $this->service->pricing_unit],
            'units' => $this->units,
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
            'location' => $this->location,
        ];
    }
}
