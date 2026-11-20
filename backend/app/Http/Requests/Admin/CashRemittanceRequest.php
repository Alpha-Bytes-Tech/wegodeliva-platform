<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;

/** Cash a rider paid back to WeGoDeliva (spec 06 §6.5), recorded by an admin. */
class CashRemittanceRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount_kobo' => ['required', 'integer', 'min:1', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
