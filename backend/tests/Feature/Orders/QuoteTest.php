<?php

use App\Enums\ClientApp;
use App\Enums\RoleName;
use App\Enums\WalletKind;
use App\Models\Coupon;
use App\Models\OrderQuote;
use App\Models\Product;
use App\Models\Vendor;
use App\Services\Ledger\WalletLedger;
use Database\Seeders\CitySeeder;
use Database\Seeders\VendorTypeSeeder;
use Illuminate\Support\Carbon;
use Tests\Support\Checkout;

beforeEach(function () {
    $this->seed([VendorTypeSeeder::class, CitySeeder::class]);
    $this->customer = makeUser();
    $this->token = Checkout::token($this->customer);
    $this->vendor = Checkout::vendor();
    $this->product = Checkout::product($this->vendor);
    $this->address = Checkout::address($this->customer);
});

it('prices a cart and stores the quote for ten minutes', function () {
    Carbon::setTestNow('2026-12-01 10:00:00');
    $response = Checkout::quote($this->token, Checkout::cart($this->vendor, [[$this->product, 2]], $this->address, ['tip_kobo' => 20000]))
        ->assertCreated()
        ->assertJsonPath('data.vendor.id', $this->vendor->uuid)
        ->assertJsonPath('data.fulfilment', 'delivery')
        ->assertJsonPath('data.address_id', $this->address->uuid)
        ->assertJsonPath('data.delivery.fee.amount_kobo', 50000)
        ->assertJsonPath('data.price.subtotal.amount_kobo', 300000)
        ->assertJsonPath('data.price.total.amount_kobo', 300000 + 50000 + 20000)
        ->assertJsonPath('data.expires_at', '2026-12-01T10:10:00+00:00');

    $options = collect($response->json('data.payment_options'))->keyBy('method');
    expect($options['wallet'])->toBe(['method' => 'wallet', 'available' => false, 'reason' => 'insufficient_balance'])
        ->and($options['pay_on_delivery']['available'])->toBeTrue()
        ->and($options['paystack']['reason'])->toBe('disabled')
        ->and($options['flutterwave']['reason'])->toBe('disabled');

    $quote = OrderQuote::query()->sole();
    expect($quote->uuid)->toBe($response->json('data.id'))
        ->and($quote->user_id)->toBe($this->customer->id)
        ->and($quote->total_kobo)->toBe(370000)
        ->and($quote->input['items'][0]['quantity'])->toBe(2);
    Carbon::setTestNow();
});

it('offers the wallet when it covers the total and refuses pay on delivery above the limit', function () {
    app(WalletLedger::class)->credit($this->customer, 3_000_000, WalletKind::TopUp, 't1');
    $big = Checkout::product($this->vendor, ['price_kobo' => 2_500_000]);

    $options = collect(Checkout::quote($this->token, Checkout::cart($this->vendor, [[$big]], null))->assertCreated()->json('data.payment_options'))->keyBy('method');

    expect($options['wallet']['available'])->toBeTrue()
        ->and($options['pay_on_delivery'])->toMatchArray(['available' => false, 'reason' => 'above_limit']);
});

it('prices pickup orders without a delivery fee and applies coupons', function () {
    Coupon::query()->create(['code' => 'TENOFF', 'discount_type' => 'percent', 'value' => 1000]);

    Checkout::quote($this->token, Checkout::cart($this->vendor, [[$this->product]], null, ['coupon_code' => 'tenoff']))
        ->assertCreated()
        ->assertJsonPath('data.delivery', null)
        ->assertJsonPath('data.price.discount.amount_kobo', 15000)
        ->assertJsonPath('data.price.coupon_code', 'TENOFF')
        ->assertJsonPath('data.price.total.amount_kobo', 135000);
});

it('refuses carts that break a business rule', function (Closure $setup, string $code, ?string $field) {
    $cart = $setup($this);

    $error = expectApiError(Checkout::quote($this->token, $cart), 422, $code);
    if ($field !== null) {
        expect($error['fields'])->toHaveKey($field);
    }
    expect(OrderQuote::query()->count())->toBe(0);
})->with([
    'store not approved' => [function ($t) {
        $t->vendor->forceFill(['status' => 'pending_approval'])->save();

        return Checkout::cart($t->vendor, [[$t->product]], null);
    }, 'vendor_unavailable', 'vendor_id'],
    'store closed' => [function ($t) {
        $t->vendor->forceFill(['manual_state' => 'closed'])->save();

        return Checkout::cart($t->vendor, [[$t->product]], $t->address);
    }, 'vendor_closed', null],
    'product of another store' => [fn ($t) => Checkout::cart($t->vendor, [[Checkout::product(Checkout::vendor())]], $t->address), 'product_unavailable', 'items.0.product_id'],
    'inactive product' => [fn ($t) => Checkout::cart($t->vendor, [[$t->product], [Checkout::product($t->vendor, ['is_active' => false])]], $t->address), 'product_unavailable', 'items.1.product_id'],
    'prescription item' => [fn ($t) => Checkout::cart($t->vendor, [[Checkout::product($t->vendor, ['requires_prescription' => true])]], $t->address), 'prescription_required', 'items.0.product_id'],
    'pickup-only item for delivery' => [fn ($t) => Checkout::cart($t->vendor, [[Checkout::product($t->vendor, ['is_deliverable' => false])]], $t->address), 'product_not_deliverable', 'items.0.product_id'],
    'more than in stock across lines' => [function ($t) {
        $p = Checkout::product($t->vendor, ['stock_qty' => 3]);

        return Checkout::cart($t->vendor, [[$p, 2], [$p, 2]], $t->address);
    }, 'out_of_stock', 'items.0.quantity'],
    'pickup not offered' => [function ($t) {
        $t->vendor->update(['accepts_pickup' => false]);

        return Checkout::cart($t->vendor, [[$t->product]], null);
    }, 'fulfilment_unavailable', 'fulfilment'],
    'address out of range' => [fn ($t) => Checkout::cart($t->vendor, [[$t->product]], Checkout::address($t->customer, 6.7, 3.385)), 'address_not_deliverable', 'address_id'],
    'below the store minimum' => [function ($t) {
        $t->vendor->update(['min_order_kobo' => 150001]);

        return Checkout::cart($t->vendor, [[$t->product]], $t->address);
    }, 'below_minimum_order', null],
    'above the store maximum' => [function ($t) {
        $t->vendor->update(['max_order_kobo' => 299999]);

        return Checkout::cart($t->vendor, [[$t->product, 2]], $t->address);
    }, 'above_maximum_order', null],
    'unknown coupon' => [fn ($t) => Checkout::cart($t->vendor, [[$t->product]], $t->address, ['coupon_code' => 'NOPE']), 'coupon_invalid', 'coupon_code'],
    'invalid options' => [fn ($t) => Checkout::cart($t->vendor, [[$t->product, 1, ['00000000-0000-0000-0000-000000000000']]], $t->address), 'invalid_options', 'items.0.option_ids'],
]);

it('applies the platform minimum and maximum too', function () {
    config(['wegodeliva.orders.min_subtotal_kobo' => 200000]);
    expectApiError(Checkout::quote($this->token, Checkout::cart($this->vendor, [[$this->product]], $this->address)), 422, 'below_minimum_order');

    config(['wegodeliva.orders.min_subtotal_kobo' => 0, 'wegodeliva.orders.max_subtotal_kobo' => 100000]);
    $error = expectApiError(Checkout::quote($this->token, Checkout::cart($this->vendor, [[$this->product]], $this->address)), 422, 'above_maximum_order');
    expect($error['meta']['maximum']['amount_kobo'])->toBe(100000);
});

it('validates the request shape and the customer\'s own address', function (array $override, string $field) {
    $cart = Checkout::cart($this->vendor, [[$this->product]], $this->address);
    $error = expectApiError(Checkout::quote($this->token, array_merge($cart, is_callable($override[0] ?? null) ? $override[0]($this) : $override)), 400, 'validation_failed');

    expect($error['fields'])->toHaveKey($field);
})->with([
    'no items' => [['items' => []], 'items'],
    'quantity too high' => [['items' => [['product_id' => '00000000-0000-0000-0000-000000000000', 'quantity' => 51]]], 'items.0.quantity'],
    'delivery without address' => [['address_id' => null], 'address_id'],
    'someone else\'s address' => [[fn ($t) => ['address_id' => Checkout::address(makeUser())->uuid]], 'address_id'],
    'tip on pickup' => [['fulfilment' => 'pickup', 'address_id' => null, 'tip_kobo' => 10000], 'tip_kobo'],
]);

it('checks scheduled times against the store\'s rules and hours', function (?Closure $setup, string $when, ?string $message) {
    Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00', 'Africa/Lagos'));
    if ($setup !== null) {
        $setup($this->vendor);
    }
    $response = Checkout::quote($this->token, Checkout::cart($this->vendor, [[$this->product]], $this->address, [
        'scheduled_for' => Carbon::parse($when, 'Africa/Lagos')->toIso8601String(),
    ]));

    if ($message === null) {
        $response->assertCreated()->assertJsonPath('data.scheduled_for', Carbon::parse($when, 'Africa/Lagos')->utc()->toIso8601String());
    } else {
        $error = expectApiError($response, 422, 'schedule_invalid');
        expect($error['fields']['scheduled_for'][0])->toBe($message);
    }
    Carbon::setTestNow();
})->with([
    'valid slot' => [null, '2026-12-01 12:00', null],
    'store does not schedule' => [fn (Vendor $v) => $v->update(['allows_scheduled_orders' => false]), '2026-12-01 12:00', 'This store does not take scheduled orders.'],
    'too soon' => [null, '2026-12-01 10:59', 'Choose a time at least 120 minutes from now.'],
    'too far ahead' => [null, '2026-12-04 09:01', 'Choose a time within 3 days.'],
    'outside hours' => [function (Vendor $v) {
        $v->hours()->delete();
        $v->hours()->create(['weekday' => 2, 'opens_at' => '08:00', 'closes_at' => '11:00']);
    }, '2026-12-01 12:00', 'The store is closed at that time.'],
]);

it('lets a closed store take a scheduled order', function () {
    Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00', 'Africa/Lagos'));
    $this->vendor->forceFill(['manual_state' => 'busy'])->save();

    $error = expectApiError(Checkout::quote($this->token, Checkout::cart($this->vendor, [[$this->product]], $this->address)), 422, 'vendor_closed');
    expect($error['meta'])->toBe(['open_state' => 'busy', 'can_schedule' => true]);
    Checkout::quote($this->token, Checkout::cart($this->vendor, [[$this->product]], $this->address, ['scheduled_for' => '2026-12-01T14:00:00+01:00']))->assertCreated();
    Carbon::setTestNow();
});

it('needs a customer-app token', function () {
    expectApiError($this->withToken(tokenFor(makeUser(RoleName::Rider), ClientApp::Rider))->postJson('/api/v1/me/cart/quote', []), 403, 'forbidden');
    expect(Product::query()->count())->toBe(1);
});
