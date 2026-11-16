<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }
}
