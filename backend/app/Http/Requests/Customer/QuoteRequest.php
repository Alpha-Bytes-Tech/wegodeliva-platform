<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\ApiRequest;

/** A cart to price (spec 04 §4.4, spec 14 "Cart quote"). Business rules are checked by QuoteBuilder. */
class QuoteRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $cfg = config('wegodeliva.orders');

        return [
            'vendor_id' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:'.$cfg['max_lines']],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.$cfg['max_quantity']],
            'items.*.option_ids' => ['sometimes', 'array', 'max:30'],
            'items.*.option_ids.*' => ['uuid'],
            'fulfilment' => ['required', 'in:delivery,pickup'],
            'address_id' => ['required_if:fulfilment,delivery', 'nullable', 'uuid'],
            'scheduled_for' => ['nullable', 'date'],
            'coupon_code' => ['nullable', 'string', 'max:32'],
            'tip_kobo' => ['nullable', 'integer', 'min:0', 'max:'.config('wegodeliva.pricing.max_tip_kobo')],
        ];
    }
}
