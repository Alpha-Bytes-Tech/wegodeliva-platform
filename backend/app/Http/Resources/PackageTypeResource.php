<?php

namespace App\Http\Resources;

use App\Models\PackageType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PackageType */
class PackageTypeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'image_path' => $this->image_path,
            'requires_stop_verification' => $this->requires_stop_verification,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];
    }
}
