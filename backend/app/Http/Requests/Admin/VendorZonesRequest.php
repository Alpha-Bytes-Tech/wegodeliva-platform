<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/** Replaces the zones a vendor delivers to, each with an optional fee override (spec 05 §5.2). */
class VendorZonesRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'zones' => ['present', 'array', 'max:100'],
            'zones.*.zone_id' => ['required', 'string', 'distinct', Rule::exists('zones', 'uuid')],
            'zones.*.delivery_fee_kobo' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ];
    }
}
