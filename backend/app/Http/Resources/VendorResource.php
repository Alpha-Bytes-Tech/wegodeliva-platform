<?php

namespace App\Http\Resources;

use App\Models\Vendor;
use App\Models\VendorHour;
use App\Models\VendorMenu;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A vendor as customers see it. `distance_m` and `deliverable` appear when the request gave a
 * point. Delivery fee estimates arrive with the pricing engine (milestone 2.2).
 *
 * @mixin Vendor
 */
class VendorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Only present when the query computed it (vendors near a point, or a point was given).
        $distance = $this->resource->getAttributes()['distance_m'] ?? null;

        return [
            'id' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'vendor_type' => $this->vendorType->slug,
            'logo_path' => $this->logo_path,
            'cover_path' => $this->cover_path,
            'address_line' => $this->address_line,
            'lat' => (float) $this->lat,
            'lng' => (float) $this->lng,
            'open_state' => $this->openState()->value,
            'accepts_delivery' => $this->accepts_delivery,
            'accepts_pickup' => $this->accepts_pickup,
            'allows_scheduled_orders' => $this->allows_scheduled_orders,
            'prep_time_minutes' => $this->prep_time_minutes,
            'delivery_time_minutes' => $this->delivery_time_minutes,
            'min_order' => Money::of($this->min_order_kobo),
            'is_featured' => $this->is_featured,
            'rating' => ['average' => (float) $this->rating_avg, 'count' => $this->rating_count],
            'distance_m' => $this->when($distance !== null, fn () => (int) $distance),
            'deliverable' => $this->when($distance !== null, fn () => $this->accepts_delivery && (int) $distance <= $this->delivery_radius_m),
            'hours' => $this->whenLoaded('hours', fn () => $this->hours->map(fn (VendorHour $h) => [
                'weekday' => $h->weekday,
                'opens_at' => substr($h->opens_at, 0, 5),
                'closes_at' => substr($h->closes_at, 0, 5),
            ])->all()),
            'menus' => $this->whenLoaded('menus', fn () => $this->menus
                ->filter(fn (VendorMenu $m) => $m->is_active)
                ->map(fn (VendorMenu $m) => ['id' => $m->uuid, 'name' => $m->name])->values()->all()),
        ];
    }
}
