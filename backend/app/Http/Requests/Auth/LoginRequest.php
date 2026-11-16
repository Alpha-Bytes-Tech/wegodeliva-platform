<?php

namespace App\Http\Requests\Auth;

use App\Enums\ClientApp;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/** Email + password sign-in from a mobile app; `app` is the role hint (spec 02 §2.4). */
class LoginRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            'password' => ['required', 'string', 'max:200'],
            'app' => ['required', Rule::in(ClientApp::mobileValues())],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function app(): ClientApp
    {
        return ClientApp::from($this->string('app')->toString());
    }
}
