<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\ApiRequest;
use App\Rules\NigerianMobile;
use App\Support\PhoneNumber;

/**
 * A parcel to price (spec 09 §9.6): package type, the pickup then the drop-offs, size and
 * weight, when, and who pays. The quote also names the chosen operator.
 */
class ParcelRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $cfg = config('wegodeliva.parcels');
        $maxStops = $cfg['multi_stop'] ? max(2, (int) $cfg['max_stops']) : 2;

        return [
            'package_type_id' => ['required', 'uuid'],
            'operator_id' => [$this->routeIs('*.parcels.quote') ? 'required' : 'prohibited', 'uuid'],
            'stops' => ['required', 'array', 'min:2', 'max:'.$maxStops],
            'stops.*.line1' => ['required', 'string', 'min:3', 'max:255'],
            'stops.*.landmark' => ['nullable', 'string', 'max:255'],
            'stops.*.area' => ['nullable', 'string', 'max:120'],
            'stops.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'stops.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'stops.*.contact_name' => ['required', 'string', 'max:120'],
            'stops.*.contact_phone' => ['required', 'string', new NigerianMobile],
            'stops.*.note' => ['nullable', 'string', 'max:255'],
            'weight_g' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'length_cm' => ['nullable', 'integer', 'min:1', 'max:300'],
            'width_cm' => ['nullable', 'integer', 'min:1', 'max:300'],
            'height_cm' => ['nullable', 'integer', 'min:1', 'max:300'],
            'payer' => ['required', 'in:sender,recipient'],
            'scheduled_for' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $stops = $this->input('stops');
        if (is_array($stops)) {
            foreach ($stops as $i => $stop) {
                if (is_array($stop) && is_string($stop['contact_phone'] ?? null)) {
                    $stops[$i]['contact_phone'] = PhoneNumber::normalise($stop['contact_phone']) ?? $stop['contact_phone'];
                }
            }
            $this->merge(['stops' => $stops]);
        }
    }
}
