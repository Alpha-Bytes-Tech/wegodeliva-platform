<?php

namespace App\Http\Resources;

use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Address */
class AddressResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'label' => $this->label,
            'line1' => $this->line1,
            'landmark' => $this->landmark,
            'area' => $this->area,
            'lat' => (float) $this->lat,
            'lng' => (float) $this->lng,
            'city' => $this->city?->name,
            'zone' => $this->zone === null ? null : ['id' => $this->zone->uuid, 'name' => $this->zone->name, 'is_campus' => $this->zone->is_campus],
            'campus_building' => $this->campus_building,
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'is_default' => $this->is_default,
        ];
    }
}
