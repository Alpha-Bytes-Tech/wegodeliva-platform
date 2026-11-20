<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;
use App\Rules\NigerianMobile;
use Illuminate\Validation\Rule;

/**
 * Admin creates a vendor and names its owner (spec 12 "Vendors"). If no account exists for the
 * owner's email, one is created (name and phone required) and the owner signs in by email code.
 */
class CreateVendorRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'vendor_type' => ['required', 'string', Rule::exists('vendor_types', 'slug')->where('is_active', true)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', new NigerianMobile],
            'email' => ['nullable', 'string', 'email:rfc', 'max:191'],
            'address_line' => ['required', 'string', 'min:3', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'owner.email' => ['required', 'string', 'email:rfc', 'max:191'],
            'owner.name' => ['nullable', 'string', 'min:2', 'max:120'],
            'owner.phone' => ['nullable', 'string', new NigerianMobile],
        ];
    }
}
