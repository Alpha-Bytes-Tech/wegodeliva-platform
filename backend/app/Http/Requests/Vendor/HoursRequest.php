<?php

namespace App\Http\Requests\Vendor;

use App\Http\Requests\ApiRequest;

/** Replaces the weekly opening hours; several periods per day are allowed (split shifts). */
class HoursRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hours' => ['present', 'array', 'max:42'],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6'],
            'hours.*.opens_at' => ['required', 'date_format:H:i'],
            'hours.*.closes_at' => ['required', 'date_format:H:i', 'different:hours.*.opens_at'],
        ];
    }
}
