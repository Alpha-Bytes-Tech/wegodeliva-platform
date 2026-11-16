<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/** Base request: normalises email and phone before validation. */
abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        if (is_string($email)) {
            $this->merge(['email' => User::normaliseEmail($email)]);
        }

        $phone = $this->input('phone');
        if (is_string($phone)) {
            $this->merge(['phone' => PhoneNumber::normalise($phone) ?? $phone]);
        }
    }

    public function email(): string
    {
        return $this->string('email')->toString();
    }
}
