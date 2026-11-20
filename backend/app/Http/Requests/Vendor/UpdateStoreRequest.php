<?php

namespace App\Http\Requests\Vendor;

use App\Enums\DeliveryFeeMode;
use App\Http\Requests\ApiRequest;
use App\Models\Vendor;
use App\Rules\NigerianMobile;
use Illuminate\Validation\Rule;

/**
 * Store profile and delivery settings (spec 10 "Profile / vendor details"). Owner only. The
 * store's location is not editable here: moving a store needs admin re-approval.
 */
class UpdateStoreRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Vendor $vendor */
        $vendor = $this->route('vendor');
        // Store images live under the store's own folder in the bucket; no other paths.
        $image = ['sometimes', 'nullable', 'string', 'max:255', 'not_regex:#\.\.#', 'regex:#^vendors/'.preg_quote($vendor->uuid, '#').'/store/[A-Za-z0-9._/-]+$#'];

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'phone' => ['sometimes', 'nullable', 'string', new NigerianMobile],
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:191'],
            'logo_path' => $image,
            'cover_path' => $image,
            'accepts_delivery' => ['sometimes', 'boolean'],
            'accepts_pickup' => ['sometimes', 'boolean'],
            'allows_scheduled_orders' => ['sometimes', 'boolean'],
            'auto_accept_orders' => ['sometimes', 'boolean'],
            'prep_time_minutes' => ['sometimes', 'integer', 'min:1', 'max:240'],
            'delivery_time_minutes' => ['sometimes', 'integer', 'min:1', 'max:480'],
            'min_order_kobo' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'max_order_kobo' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000000'],
            'delivery_fee_mode' => ['sometimes', Rule::enum(DeliveryFeeMode::class)],
            'base_delivery_fee_kobo' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'delivery_fee_kobo' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'delivery_radius_m' => ['sometimes', 'integer', 'min:100', 'max:50000'],
            'vat_registered' => ['sometimes', 'boolean'],
            'tin' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9-]{8,20}$/'],
        ];
    }
}
