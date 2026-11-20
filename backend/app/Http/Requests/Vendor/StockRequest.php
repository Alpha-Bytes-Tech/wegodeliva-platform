<?php

namespace App\Http\Requests\Vendor;

use App\Http\Requests\ApiRequest;

/** Quick stock edit (spec 10 "Products"): a quantity, or null for unlimited. */
class StockRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['stock_qty' => ['present', 'nullable', 'integer', 'min:0', 'max:1000000']];
    }
}
