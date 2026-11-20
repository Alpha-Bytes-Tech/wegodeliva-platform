<?php

namespace App\Services\Pricing;

use App\Enums\CouponAppliesTo;
use App\Enums\CouponScope;
use App\Enums\DiscountType;
use App\Enums\Party;
use App\Exceptions\ApiException;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Vendor;
use App\Support\Money;

/**
 * Coupon validation and amount (spec 05 §5.4). Runs at quote and again at placement. For
 * "vendors" and "products" scopes the coupon's `vendors` / `products` relations must be loaded.
 * A failure is a 422 `coupon_invalid` whose `meta.reason` says why.
 */
final class CouponRules
{
    public function apply(Coupon $coupon, CouponContext $ctx): CouponDiscount
    {
        $this->checkAvailability($coupon, $ctx);

        $eligible = $this->eligibleLines($coupon, $ctx);
        $eligibleSubtotal = array_sum(array_map(fn (PricedLine $l) => $l->lineTotalKobo, $eligible));
        if ($eligibleSubtotal < $coupon->min_order_kobo) {
            $this->fail('min_order', ['min_order' => Money::of($coupon->min_order_kobo)]);
        }

        if ($coupon->applies_to === CouponAppliesTo::DeliveryFee) {
            $fee = $ctx->deliveryFeeKobo ?? 0;
            if ($fee === 0) {
                $this->fail('delivery_fee_required');
            }

            // Delivery coupons are always platform-funded (the vendor does not receive the fee).
            return new CouponDiscount($coupon, Party::Platform, 0, $this->amount($coupon, $fee), []);
        }

        $discount = $this->amount($coupon, $eligibleSubtotal);
        $indexes = array_keys($eligible);
        $shares = $discount === 0
            ? array_fill(0, count($indexes), 0)
            : Money::allocate($discount, array_map(fn (PricedLine $l) => $l->lineTotalKobo, array_values($eligible)));

        return new CouponDiscount($coupon, $coupon->funded_by, $discount, 0, array_combine($indexes, $shares));
    }

    private function checkAvailability(Coupon $coupon, CouponContext $ctx): void
    {
        if (! $coupon->is_active) {
            $this->fail('inactive');
        }
        if ($coupon->starts_at !== null && $ctx->now->lt($coupon->starts_at)) {
            $this->fail('not_started');
        }
        if ($coupon->ends_at !== null && $ctx->now->gte($coupon->ends_at)) {
            $this->fail('expired');
        }
        if ($coupon->max_uses_total !== null && $ctx->usesTotal >= $coupon->max_uses_total) {
            $this->fail('usage_limit_reached');
        }
        if ($coupon->max_uses_per_user !== null && $ctx->usesByCustomer >= $coupon->max_uses_per_user) {
            $this->fail('customer_limit_reached');
        }
        if ($coupon->first_order_only && ! $ctx->isFirstOrder) {
            $this->fail('first_order_only');
        }

        $applies = match ($coupon->scope) {
            CouponScope::All, CouponScope::Products => true,
            CouponScope::VendorType => $coupon->vendor_type_id === $ctx->vendorTypeId,
            CouponScope::Vendors => $coupon->vendors->contains(fn (Vendor $v) => $v->id === $ctx->vendorId),
        };
        if (! $applies) {
            $this->fail('not_applicable');
        }
    }

    /** @return array<int, PricedLine> keyed by line index */
    private function eligibleLines(Coupon $coupon, CouponContext $ctx): array
    {
        if ($coupon->scope !== CouponScope::Products) {
            return $ctx->lines;
        }

        $ids = $coupon->products->map(fn (Product $p) => $p->id)->all();
        $eligible = array_filter($ctx->lines, fn (PricedLine $l) => in_array($l->productId, $ids, true));
        if ($eligible === []) {
            $this->fail('not_applicable');
        }

        return $eligible;
    }

    /** Percent or fixed, capped by max_discount and never more than the base. */
    private function amount(Coupon $coupon, int $base): int
    {
        $amount = $coupon->discount_type === DiscountType::Percent
            ? Money::percentOf($base, $coupon->value)
            : $coupon->value;

        if ($coupon->max_discount_kobo !== null) {
            $amount = min($amount, $coupon->max_discount_kobo);
        }

        return min($amount, $base);
    }

    /** @param  array<string, mixed>  $meta */
    private function fail(string $reason, array $meta = []): never
    {
        throw new ApiException('coupon_invalid', 422, fields: ['coupon_code' => [self::MESSAGES[$reason]]], meta: ['reason' => $reason] + $meta);
    }

    private const MESSAGES = [
        'inactive' => 'This code is not active.',
        'not_started' => 'This code is not active yet.',
        'expired' => 'This code has expired.',
        'usage_limit_reached' => 'This code has been fully used.',
        'customer_limit_reached' => 'You have already used this code.',
        'first_order_only' => 'This code is for your first order only.',
        'not_applicable' => 'This code does not apply to this order.',
        'min_order' => 'Your order is below the minimum for this code.',
        'delivery_fee_required' => 'This code takes money off delivery, and this order has no delivery fee.',
    ];
}
