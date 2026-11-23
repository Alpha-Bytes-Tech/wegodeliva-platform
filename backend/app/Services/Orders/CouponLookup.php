<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Pricing\CouponContext;
use App\Services\Pricing\CouponDiscount;
use App\Services\Pricing\CouponRules;
use App\Services\Pricing\PricedLine;

/** Finds a coupon by code and applies it to priced lines for this customer (spec 05 §5.4). */
final class CouponLookup
{
    public function __construct(private readonly CouponRules $rules) {}

    /** @param  list<PricedLine>  $lines */
    public function apply(User $customer, Vendor $vendor, string $code, array $lines, ?int $deliveryFee): CouponDiscount
    {
        $coupon = Coupon::query()->where('code', Coupon::normaliseCode($code))->with('vendors', 'products')->first();
        if ($coupon === null) {
            throw new ApiException('coupon_invalid', 422, fields: ['coupon_code' => ['We do not recognise this code.']], meta: ['reason' => 'not_found']);
        }

        return $this->rules->apply($coupon, new CouponContext(
            vendorId: $vendor->id,
            vendorTypeId: $vendor->vendor_type_id,
            lines: $lines,
            deliveryFeeKobo: $deliveryFee,
            isFirstOrder: ! Order::query()->where('customer_id', $customer->id)->where('status', '!=', OrderStatus::Cancelled->value)->exists(),
            usesTotal: CouponRedemption::query()->counted()->where('coupon_id', $coupon->id)->count(),
            usesByCustomer: CouponRedemption::query()->counted()->where('coupon_id', $coupon->id)->where('user_id', $customer->id)->count(),
            now: now(),
        ));
    }
}
