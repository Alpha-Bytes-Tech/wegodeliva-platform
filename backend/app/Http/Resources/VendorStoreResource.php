<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorHour;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A vendor as its members and admins see it: settings, status and (when loaded) members,
 * hours and zones.
 *
 * @mixin Vendor
 */
class VendorStoreResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'vendor_type' => $this->vendorType->slug,
            'status' => $this->status->value,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'phone' => $this->phone,
            'email' => $this->email,
            'logo_path' => $this->logo_path,
            'cover_path' => $this->cover_path,
            'address_line' => $this->address_line,
            'lat' => (float) $this->lat,
            'lng' => (float) $this->lng,
            'open_state' => $this->openState()->value,
            'manual_state' => $this->manual_state?->value,
            'manual_state_until' => $this->manual_state_until?->toIso8601String(),
            'accepts_delivery' => $this->accepts_delivery,
            'accepts_pickup' => $this->accepts_pickup,
            'allows_scheduled_orders' => $this->allows_scheduled_orders,
            'auto_accept_orders' => $this->auto_accept_orders,
            'uses_own_riders' => $this->uses_own_riders,
            'prep_time_minutes' => $this->prep_time_minutes,
            'delivery_time_minutes' => $this->delivery_time_minutes,
            'min_order_kobo' => $this->min_order_kobo,
            'max_order_kobo' => $this->max_order_kobo,
            'delivery_fee_mode' => $this->delivery_fee_mode->value,
            'base_delivery_fee_kobo' => $this->base_delivery_fee_kobo,
            'delivery_fee_kobo' => $this->delivery_fee_kobo,
            'delivery_radius_m' => $this->delivery_radius_m,
            'commission_rate_bp' => $this->commission_rate_bp,
            'vat_registered' => $this->vat_registered,
            'tin' => $this->tin,
            'my_role' => $this->whenPivotLoaded('vendor_members', fn () => self::pivotValue($this->resource, 'role')),
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn (User $u) => [
                'id' => $u->uuid,
                'name' => $u->name,
                'email' => $u->email,
                'role' => self::pivotValue($u, 'role'),
            ])->all()),
            'hours' => $this->whenLoaded('hours', fn () => $this->hours->map(fn (VendorHour $h) => [
                'weekday' => $h->weekday,
                'opens_at' => substr($h->opens_at, 0, 5),
                'closes_at' => substr($h->closes_at, 0, 5),
            ])->all()),
            'fees' => FeeResource::collection($this->whenLoaded('fees')),
            'zones' => $this->whenLoaded('zones', fn () => $this->zones->map(fn (Zone $z) => [
                'id' => $z->uuid,
                'name' => $z->name,
                'delivery_fee_kobo' => self::pivotValue($z, 'delivery_fee_kobo'),
            ])->all()),
        ];
    }

    private static function pivotValue(Model $model, string $key): mixed
    {
        $pivot = $model->relationLoaded('pivot') ? $model->getRelation('pivot') : null;

        return $pivot instanceof Model ? $pivot->getAttribute($key) : null;
    }
}
