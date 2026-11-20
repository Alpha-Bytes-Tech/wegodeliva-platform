<?php

namespace App\Http\Requests\Vendor;

use App\Http\Requests\ApiRequest;

/**
 * Manual store status (spec 10 "Store status"): open / busy / closed for `minutes` (or until
 * changed back), or `schedule` to follow opening hours again.
 */
class StoreStatusRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'state' => ['required', 'in:open,busy,closed,schedule'],
            'minutes' => ['nullable', 'integer', 'min:5', 'max:1440', 'prohibited_if:state,schedule'],
        ];
    }
}
