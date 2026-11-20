<?php

namespace App\Http\Resources;

use App\Models\VendorType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VendorType */
class VendorTypeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'icon_path' => $this->icon_path,
            'color' => $this->color,
        ];
    }
}
