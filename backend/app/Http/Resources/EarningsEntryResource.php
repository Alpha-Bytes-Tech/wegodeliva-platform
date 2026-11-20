<?php

namespace App\Http\Resources;

use App\Models\EarningsEntry;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EarningsEntry */
class EarningsEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'kind' => $this->kind->value,
            'bucket' => $this->bucket->value,
            'amount' => Money::of($this->amount_kobo),
            'bucket_after' => Money::of($this->bucket_after_kobo),
            'note' => $this->note,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
