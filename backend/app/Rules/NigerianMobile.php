<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NigerianMobile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || PhoneNumber::normalise($value) === null) {
            $fail('Enter a valid Nigerian mobile number, for example 0803 123 4567.');
        }
    }
}
