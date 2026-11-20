<?php

use App\Enums\Party;
use App\Exceptions\ApiException;
use App\Services\Pricing\CouponRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Tests\Support\Pricing;

function couponFailure(Closure $call): array
{
    try {
        $call();
    } catch (ApiException $e) {
        return [$e->errorCode, $e->status, $e->meta, $e->fields];
    }
    throw new RuntimeException('Expected the coupon to be refused.');
}

it('takes a percentage off the subtotal, capped, and spreads it over the lines', function () {
    $lines = [Pricing::line(300000), Pricing::line(200000)];
    $coupon = Pricing::coupon(['discount_type' => 'percent', 'value' => 2000, 'max_discount_kobo' => 50000]);

    $result = (new CouponRules)->apply($coupon, Pricing::context($lines));

    // 20% of ₦5,000 is ₦1,000, capped at ₦500.
    expect($result->discountKobo)->toBe(50000)
        ->and($result->deliveryDiscountKobo)->toBe(0)
        ->and($result->fundedBy)->toBe(Party::Vendor)
        ->and($result->lineDiscounts)->toBe([0 => 30000, 1 => 20000])
        ->and($result->lineDiscount(5))->toBe(0);
});

it('never discounts more than the eligible subtotal', function () {
    $result = (new CouponRules)->apply(Pricing::coupon(['value' => 900000]), Pricing::context([Pricing::line(300000)]));

    expect($result->discountKobo)->toBe(300000);
});

it('limits product coupons to matching lines, including the minimum order', function () {
    $lines = [Pricing::line(100000, productId: 501), Pricing::line(400000, productId: 502)];
    $coupon = Pricing::coupon(['discount_type' => 'percent', 'value' => 5000, 'scope' => 'products', 'min_order_kobo' => 100000, 'funded_by' => 'platform']);
    $coupon->setRelation('products', new Collection([Pricing::product(1)->forceFill(['id' => 501])]));

    $result = (new CouponRules)->apply($coupon, Pricing::context($lines));
    expect($result->discountKobo)->toBe(50000)
        ->and($result->lineDiscounts)->toBe([0 => 50000])
        ->and($result->fundedBy)->toBe(Party::Platform);

    $coupon->min_order_kobo = 100001;
    [, , $meta] = couponFailure(fn () => (new CouponRules)->apply($coupon, Pricing::context($lines)));
    expect($meta)->toBe(['reason' => 'min_order', 'min_order' => ['amount_kobo' => 100001, 'formatted' => '₦1,000.01']]);

    $coupon->setRelation('products', new Collection([Pricing::product(1)->forceFill(['id' => 999])]));
    [, , $meta] = couponFailure(fn () => (new CouponRules)->apply($coupon, Pricing::context($lines)));
    expect($meta['reason'])->toBe('not_applicable');
});

it('gives a zero discount on free lines without failing', function () {
    $result = (new CouponRules)->apply(Pricing::coupon(['discount_type' => 'percent', 'value' => 1000]), Pricing::context([Pricing::line(0)]));

    expect($result->discountKobo)->toBe(0)->and($result->lineDiscounts)->toBe([0 => 0]);
});

it('takes money off delivery, always platform-funded', function (string $type, int $value, ?int $cap, int $expected) {
    $coupon = Pricing::coupon(['applies_to' => 'delivery_fee', 'discount_type' => $type, 'value' => $value, 'max_discount_kobo' => $cap, 'funded_by' => 'platform']);

    $result = (new CouponRules)->apply($coupon, Pricing::context([Pricing::line(100000)], deliveryFee: 80000));

    expect($result->deliveryDiscountKobo)->toBe($expected)
        ->and($result->discountKobo)->toBe(0)
        ->and($result->lineDiscounts)->toBe([])
        ->and($result->fundedBy)->toBe(Party::Platform);
})->with([
    'half off' => ['percent', 5000, null, 40000],
    'free delivery' => ['percent', 10000, null, 80000],
    'capped' => ['percent', 10000, 30000, 30000],
    'fixed above the fee' => ['fixed', 100000, null, 80000],
]);

it('refuses a delivery coupon on an order without a delivery fee', function (?int $fee) {
    $coupon = Pricing::coupon(['applies_to' => 'delivery_fee', 'funded_by' => 'platform']);

    [$code, $status, $meta, $fields] = couponFailure(fn () => (new CouponRules)->apply($coupon, Pricing::context([Pricing::line(100000)], deliveryFee: $fee)));
    expect($code)->toBe('coupon_invalid')->and($status)->toBe(422)
        ->and($meta)->toBe(['reason' => 'delivery_fee_required'])
        ->and($fields)->toHaveKey('coupon_code');
})->with(['pickup' => [null], 'free delivery' => [0]]);

it('checks status, dates, usage limits and first-order rules', function (array $attributes, array $context, string $reason) {
    [, , $meta] = couponFailure(fn () => (new CouponRules)->apply(Pricing::coupon($attributes), Pricing::context([Pricing::line(100000)], 100000, $context)));

    expect($meta['reason'])->toBe($reason);
})->with([
    'switched off' => [['is_active' => false], [], 'inactive'],
    'not started' => [['starts_at' => '2026-12-01 12:00:01'], [], 'not_started'],
    'ended' => [['ends_at' => '2026-12-01 12:00:00'], [], 'expired'],
    'used up' => [['max_uses_total' => 100], ['usesTotal' => 100], 'usage_limit_reached'],
    'customer used it' => [['max_uses_per_user' => 1], ['usesByCustomer' => 1], 'customer_limit_reached'],
    'first order only' => [['first_order_only' => true], ['isFirstOrder' => false], 'first_order_only'],
    'other vendor type' => [['scope' => 'vendor_type', 'vendor_type_id' => 2], ['vendorTypeId' => 1], 'not_applicable'],
    'vendor not listed' => [['scope' => 'vendors'], ['vendorId' => 10], 'not_applicable'],
    'below minimum' => [['min_order_kobo' => 100001], [], 'min_order'],
]);

it('accepts coupons inside their window and scope', function () {
    $coupon = Pricing::coupon([
        'starts_at' => '2026-12-01 12:00:00', 'ends_at' => '2026-12-01 12:00:01',
        'max_uses_total' => 100, 'max_uses_per_user' => 2, 'first_order_only' => true,
        'scope' => 'vendors', 'min_order_kobo' => 100000,
    ]);
    $coupon->setRelation('vendors', new Collection([Pricing::vendor(10)]));
    $context = Pricing::context([Pricing::line(100000)], 100000, ['usesTotal' => 99, 'usesByCustomer' => 1, 'isFirstOrder' => true, 'now' => Carbon::parse('2026-12-01 12:00:00')]);

    expect((new CouponRules)->apply($coupon, $context)->discountKobo)->toBe(50000);

    $typed = Pricing::coupon(['scope' => 'vendor_type', 'vendor_type_id' => 1]);
    expect((new CouponRules)->apply($typed, Pricing::context([Pricing::line(100000)]))->discountKobo)->toBe(50000);
});
