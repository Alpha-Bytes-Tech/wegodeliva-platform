<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;
use App\Models\User;
use App\Rules\NigerianMobile;
use Illuminate\Validation\Rule;

/** Re-confirms the phone number after the email was first verified (ADR 0012). */
class ConfirmPhoneRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'phone' => ['required', 'string', new NigerianMobile, Rule::unique('users', 'phone')->ignore($user->getKey())],
        ];
    }
}
