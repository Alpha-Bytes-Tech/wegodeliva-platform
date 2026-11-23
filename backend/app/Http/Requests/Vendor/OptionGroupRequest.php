<?php

namespace App\Http\Requests\Vendor;

use App\Http\Requests\ApiRequest;

/** Option group with its choices; on create the options can be sent inline. */
class OptionGroupRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        $rules = [
            'name' => [$required, 'string', 'min:1', 'max:80'],
            'is_required' => ['sometimes', 'boolean'],
            'min_select' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'max_select' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        // Choices can be sent inline when creating a group; afterwards they have their own endpoints.
        if ($this->isMethod('POST')) {
            $rules += [
                'options' => ['sometimes', 'array', 'max:50'],
                'options.*.name' => ['required', 'string', 'min:1', 'max:80'],
                'options.*.description' => ['nullable', 'string', 'max:255'],
                'options.*.price_kobo' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
                'options.*.per_unit' => ['sometimes', 'boolean'],
                'options.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            ];
        }

        return $rules;
    }
}
