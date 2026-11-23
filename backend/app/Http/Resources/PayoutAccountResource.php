<?php

namespace App\Http\Resources;

use App\Models\PayoutAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bank account with the number masked.
 *
 * @mixin PayoutAccount
 */
class PayoutAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'bank_code' => $this->bank_code,
            'bank_name' => $this->bank_name,
            'account_number' => $this->maskedNumber(),
            'account_name' => $this->account_name,
            'status' => $this->status,
            'is_default' => $this->is_default,
        ];
    }
}
