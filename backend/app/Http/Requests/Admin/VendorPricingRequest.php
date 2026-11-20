<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/** A vendor's commission rate override and the service fees that apply to it (spec 12 "Vendors"). */
class VendorPricingRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // null = the platform default (5%).
            'commission_rate_bp' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'fee_ids' => ['sometimes', 'array', 'max:20'],
            'fee_ids.*' => ['string', 'distinct', Rule::exists('fees', 'uuid')],
        ];
    }
}
