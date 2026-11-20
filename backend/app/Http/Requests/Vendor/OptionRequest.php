<?php

namespace App\Http\Requests\Vendor;

use App\Http\Requests\ApiRequest;

class OptionRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:1', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'price_kobo' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
