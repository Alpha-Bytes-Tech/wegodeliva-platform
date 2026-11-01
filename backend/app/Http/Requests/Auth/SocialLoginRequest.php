<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;
use App\Rules\NigerianMobile;

/**
 * Google/Apple sign-in for the customer app. A first-time sign-in must include a phone number
 * (spec 03 §3.1: phone is required); the app shows "Complete profile" when the API answers
 * `phone_required`, then resends the same ID token with the phone.
 */
class SocialLoginRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id_token' => ['required', 'string', 'max:8192'],
            'name' => ['nullable', 'string', 'min:2', 'max:120'],
            'phone' => ['nullable', 'string', new NigerianMobile, 'unique:users,phone'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
