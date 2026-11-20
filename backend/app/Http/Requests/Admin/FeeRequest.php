<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;
use App\Models\Fee;

/** Creates or edits a service fee (spec 05 §5.5). On edit, missing fields keep their values. */
class FeeRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $fee = $this->route('fee');
        if ($fee instanceof Fee) {
            $this->merge(array_merge([
                'name' => $fee->name,
                'type' => $fee->type->value,
                'value' => $fee->value,
                'beneficiary' => $fee->beneficiary->value,
                'is_active' => $fee->is_active,
            ], $this->all()));
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'type' => ['required', 'in:percent,fixed'],
            'value' => ['required', 'integer', 'min:1', $this->input('type') === 'percent' ? 'max:10000' : 'max:10000000'],
            'beneficiary' => ['required', 'in:platform,vendor'],
            'is_active' => ['boolean'],
        ];
    }
}
