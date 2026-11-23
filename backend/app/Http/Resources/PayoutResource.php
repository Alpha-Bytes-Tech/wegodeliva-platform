<?php

namespace App\Http\Resources;

use App\Models\Payout;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payout */
class PayoutResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'amount' => Money::of($this->amount_kobo),
            'status' => $this->status,
            'gateway' => $this->gateway->value,
            'reference' => $this->transfer_reference,
            'account' => $this->whenLoaded('payoutAccount', fn () => (new PayoutAccountResource($this->payoutAccount))->toArray($request)),
            'note' => $this->note,
            'failure_reason' => $this->failure_reason,
            'requested_at' => $this->created_at->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
