<?php

namespace App\Http\Resources;

use App\Models\WalletTransaction;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WalletTransaction */
class WalletTransactionResource extends JsonResource
{
    public bool $forAdmin = false;

    /** @return array<int|string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'direction' => $this->direction,
            'amount' => Money::of($this->amount_kobo),
            'balance_after' => Money::of($this->balance_after_kobo),
            'kind' => $this->kind->value,
            'label' => $this->kind->label(),
            'note' => $this->note,
            'created_at' => $this->created_at->toIso8601String(),
            $this->mergeWhen($this->forAdmin, fn () => [
                'reference' => $this->reference,
                'gateway' => $this->gateway,
                'created_by' => $this->created_by,
            ]),
        ];
    }
}
