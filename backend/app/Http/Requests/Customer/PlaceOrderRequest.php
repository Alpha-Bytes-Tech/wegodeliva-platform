<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Validator;

/** Places a quoted cart. Needs an Idempotency-Key header (spec 14 §14.1). */
class PlaceOrderRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quote_id' => ['required', 'uuid'],
            'payment_method' => ['required', 'in:paystack,flutterwave,wallet,pay_on_delivery'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $key = $this->idempotencyKey();
            if ($key === '' || strlen($key) > 100 || preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1) {
                $validator->errors()->add('idempotency_key', 'Send an Idempotency-Key header (letters, digits, - or _, up to 100).');
            }
        }];
    }

    public function idempotencyKey(): string
    {
        return trim((string) $this->header('Idempotency-Key'));
    }
}
