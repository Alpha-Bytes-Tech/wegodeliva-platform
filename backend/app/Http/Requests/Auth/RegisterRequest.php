<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;
use App\Rules\NigerianMobile;
use Illuminate\Validation\Rules\Password;

/**
 * Customer sign-up (spec 09 §9.1). There is deliberately no `role` field: the role is always
 * `customer` and anything else the client sends is ignored (legacy-notes D1).
 */
class RegisterRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:191', 'unique:users,email'],
            'phone' => ['required', 'string', new NigerianMobile, 'unique:users,phone'],
            'password' => ['nullable', 'string', 'max:72', Password::min(8)->letters()->numbers()],
            'referral_code' => ['nullable', 'string', 'max:12', 'exists:users,referral_code'],
            'accept_terms' => ['accepted'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $code = $this->input('referral_code');
        if (is_string($code)) {
            $this->merge(['referral_code' => strtoupper(trim($code))]);
        }
    }
}
