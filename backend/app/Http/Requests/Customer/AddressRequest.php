<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\ApiRequest;
use App\Rules\NigerianMobile;
use App\Support\PhoneNumber;

/** Create (POST) or update (PATCH, all fields optional) a saved address (spec 03 §3.2). */
class AddressRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'label' => ['nullable', 'string', 'max:40'],
            'line1' => [$required, 'string', 'min:3', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:120'],
            'lat' => [$required, 'numeric', 'between:-90,90'],
            'lng' => [$required, 'numeric', 'between:-180,180'],
            'campus_building' => ['nullable', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', new NigerianMobile],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $phone = $this->input('contact_phone');
        if (is_string($phone)) {
            $this->merge(['contact_phone' => PhoneNumber::normalise($phone) ?? $phone]);
        }
    }
}
