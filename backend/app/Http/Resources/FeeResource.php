<?php

namespace App\Http\Resources;

use App\Models\Fee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Fee */
class FeeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'type' => $this->type->value,
            'value' => $this->value,
            'beneficiary' => $this->beneficiary->value,
            'is_active' => $this->is_active,
        ];
    }
}
