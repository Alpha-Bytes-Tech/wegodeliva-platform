<?php

namespace App\Http\Requests\Auth;

use App\Enums\ClientApp;
use App\Enums\OtpPurpose;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class SendOtpRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            'purpose' => ['sometimes', Rule::in([OtpPurpose::Login->value, OtpPurpose::Reset->value])],
            'app' => ['required', Rule::enum(ClientApp::class)],
        ];
    }

    public function purpose(): OtpPurpose
    {
        return OtpPurpose::from($this->string('purpose', OtpPurpose::Login->value)->toString());
    }

    public function app(): ClientApp
    {
        return ClientApp::from($this->string('app')->toString());
    }
}
