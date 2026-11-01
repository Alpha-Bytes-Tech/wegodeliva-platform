<?php

namespace App\Http\Requests;

class RegisterDeviceRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fcm_token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'in:android,ios,web'],
        ];
    }
}
