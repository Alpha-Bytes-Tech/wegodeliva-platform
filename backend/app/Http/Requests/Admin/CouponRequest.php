<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creates or edits a coupon (spec 05 §5.4, spec 12.6). On edit, fields left out keep their
 * current values and the whole coupon is validated again, so a change cannot leave it
 * inconsistent (e.g. a delivery-fee coupon funded by vendors). The code cannot change.
 */
class CouponRequest extends ApiRequest
{
    private function coupon(): ?Coupon
    {
        $coupon = $this->route('coupon');

        return $coupon instanceof Coupon ? $coupon : null;
    }

    protected function prepareForValidation(): void
    {
        $coupon = $this->coupon();
        if ($coupon !== null) {
            $coupon->loadMissing('vendorType', 'vendors', 'products');
            $this->merge(array_merge([
                'discount_type' => $coupon->discount_type->value,
                'value' => $coupon->value,
                'max_discount_kobo' => $coupon->max_discount_kobo,
                'min_order_kobo' => $coupon->min_order_kobo,
                'applies_to' => $coupon->applies_to->value,
                'scope' => $coupon->scope->value,
                'vendor_type' => $coupon->vendorType?->slug,
                'vendor_ids' => $coupon->vendors->map(fn (Vendor $v) => $v->uuid)->all(),
                'product_ids' => $coupon->products->map(fn (Product $p) => $p->uuid)->all(),
                'starts_at' => $coupon->starts_at?->toIso8601String(),
                'ends_at' => $coupon->ends_at?->toIso8601String(),
                'max_uses_total' => $coupon->max_uses_total,
                'max_uses_per_user' => $coupon->max_uses_per_user,
                'first_order_only' => $coupon->first_order_only,
                'funded_by' => $coupon->funded_by->value,
                'is_active' => $coupon->is_active,
            ], $this->all()));
        }

        if (is_string($this->input('code'))) {
            $this->merge(['code' => Coupon::normaliseCode($this->input('code'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->coupon() === null;

        return [
            'code' => $creating
                ? ['required', 'string', 'regex:/^[A-Z0-9_-]{3,32}$/', Rule::unique('coupons', 'code')]
                : ['prohibited'],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['required', 'in:percent,fixed'],
            'value' => ['required', 'integer', 'min:1', $this->input('discount_type') === 'percent' ? 'max:10000' : 'max:100000000'],
            'max_discount_kobo' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'min_order_kobo' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'applies_to' => ['required', 'in:order_subtotal,delivery_fee'],
            'scope' => ['required', 'in:all,vendor_type,vendors,products'],
            'vendor_type' => ['nullable', 'required_if:scope,vendor_type', 'string', Rule::exists('vendor_types', 'slug')],
            'vendor_ids' => ['nullable', 'required_if:scope,vendors', 'array', 'max:500'],
            'vendor_ids.*' => ['string', 'distinct', Rule::exists('vendors', 'uuid')->whereNull('deleted_at')],
            'product_ids' => ['nullable', 'required_if:scope,products', 'array', 'max:500'],
            'product_ids.*' => ['string', 'distinct', Rule::exists('products', 'uuid')->whereNull('deleted_at')],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'max_uses_total' => ['nullable', 'integer', 'min:1'],
            'max_uses_per_user' => ['nullable', 'integer', 'min:1'],
            'first_order_only' => ['boolean'],
            // Coupons are vendor-funded by default (owner decision 2026-11-01).
            'funded_by' => ['nullable', 'in:platform,vendor'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('applies_to') === 'delivery_fee' && $this->input('funded_by', 'vendor') !== 'platform') {
                $validator->errors()->add('funded_by', 'Delivery-fee coupons are always funded by the platform.');
            }
        }];
    }
}
