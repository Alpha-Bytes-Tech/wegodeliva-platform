<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;

/**
 * A manual wallet or earnings adjustment (spec 06 §6.4): signed amount, a reason the user will
 * see, and a client-generated key so a retried request is not applied twice.
 */
class LedgerAdjustmentRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount_kobo' => ['required', 'integer', 'not_in:0', 'between:-100000000,100000000'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
