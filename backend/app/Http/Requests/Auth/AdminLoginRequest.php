<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

class AdminLoginRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            'password' => ['required', 'string', 'max:200'],
        ];
    }
}
