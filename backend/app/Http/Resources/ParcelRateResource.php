<?php

namespace App\Http\Resources;

use App\Models\ParcelRate;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ParcelRate */
class ParcelRateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'package_type' => ['id' => $this->packageType->uuid, 'name' => $this->packageType->name],
            'base_price' => Money::of($this->base_price_kobo),
            'distance_rate' => Money::of($this->distance_rate_kobo),
            'distance_per_km' => $this->distance_per_km,
            'size_rate' => Money::of($this->size_rate_kobo),
            'size_per_kg' => $this->size_per_kg,
            'extra_stop_fee' => Money::of($this->extra_stop_fee_kobo),
            'max_advance_booking_days' => $this->max_advance_booking_days,
            'auto_dispatch' => $this->auto_dispatch,
            'requires_dimensions' => $this->requires_dimensions,
            'is_active' => $this->is_active,
        ];
    }
}
