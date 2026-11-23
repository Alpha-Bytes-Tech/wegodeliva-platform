<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\ApiRequest;

/** A service booking to price (spec 09 §9.9 "Book a service"). Business rules are checked by BookingQuoteBuilder. */
class BookingRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'service_id' => ['required', 'uuid'],
            'units' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'option_ids' => ['sometimes', 'array', 'max:30'],
            'option_ids.*' => ['uuid'],
            'starts_at' => ['required', 'date'],
            'location' => ['required', 'in:at_customer,at_provider'],
            'address_id' => ['required_if:location,at_customer', 'nullable', 'uuid'],
            'coupon_code' => ['nullable', 'string', 'max:32'],
        ];
    }
}
