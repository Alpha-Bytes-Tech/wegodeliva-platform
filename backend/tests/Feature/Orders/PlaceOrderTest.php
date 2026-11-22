<?php

use App\Enums\WalletKind;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Fee;
use App\Models\Order;
use App\Models\OrderQuote;
use App\Models\Product;
use App\Models\WalletTransaction;
use App\Services\Ledger\WalletLedger;
use Database\Seeders\CitySeeder;
use Database\Seeders\VendorTypeSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Checkout;

beforeEach(function () {
    $this->seed([VendorTypeSeeder::class, CitySeeder::class]);
    $this->customer = makeUser();
    $this->token = Checkout::token($this->customer);
    $this->vendor = Checkout::vendor();
    $this->product = Checkout::product($this->vendor, ['stock_qty' => 10, 'name' => 'Jollof rice']);
    $this->address = Checkout::address($this->customer);
});

function quoteId(array $cart): string
{
    return Checkout::quote(test()->token, $cart)->assertCreated()->json('data.id');
}

it('places a pay-on-delivery order with snapshots, stock taken and history recorded', function () {
    $group = $this->vendor->optionGroups()->create(['name' => 'Protein', 'max_select' => 1]);
    $chicken = $group->options()->create(['name' => 'Chicken', 'price_kobo' => 50000]);
    $this->product->optionGroups()->attach($group->id);
    $quote = quoteId(Checkout::cart($this->vendor, [[$this->product, 2, [$chicken->uuid]]], $this->address, ['tip_kobo' => 10000]));

    $response = Checkout::place($this->token, $quote, extra: ['note' => 'Extra pepper'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'placed')
        ->assertJsonPath('data.payment_status', 'unpaid')
        ->assertJsonPath('data.payment_method', 'pay_on_delivery')
        ->assertJsonPath('data.vendor.id', $this->vendor->uuid)
        ->assertJsonPath('data.items.0.name', 'Jollof rice')
        ->assertJsonPath('data.items.0.options.0.option', 'Chicken')
        ->assertJsonPath('data.items.0.line_total.amount_kobo', 400000)
        ->assertJsonPath('data.total.amount_kobo', 400000 + 50000 + 10000)
        ->assertJsonPath('data.address.landmark', 'Opposite the mosque')
        ->assertJsonPath('data.note', 'Extra pepper')
        ->assertJsonPath('data.can_cancel', true)
        ->assertJsonPath('data.timeline.0.status', 'placed');

    $order = Order::query()->sole();
    expect($response->json('data.code'))->toMatch('/^[A-HJ-KM-NP-Z2-9]{8}$/')
        ->and($response->json('data.handover_code'))->toMatch('/^\d{4}$/')
        ->and($order->handover_code)->toBe($response->json('data.handover_code'))
        ->and(DB::table('orders')->value('handover_code'))->not->toBe($order->handover_code) // encrypted at rest
        ->and($order->type)->toBe('food')
        ->and($order->placed_at)->not->toBeNull()
        ->and($order->distance_m)->toBeGreaterThan(1000)
        ->and($order->estimated_delivery_at)->not->toBeNull()
        ->and($this->product->fresh()->stock_qty)->toBe(8)
        ->and($order->items()->sole()->stock_reserved)->toBeTrue()
        ->and($order->history()->sole()->actor_role->value)->toBe('customer');

    // Editing the address later does not change the order.
    $this->address->update(['landmark' => 'Changed']);
    $this->withToken($this->token)->getJson("/api/v1/me/orders/{$order->code}")->assertOk()->assertJsonPath('data.address.landmark', 'Opposite the mosque');
});

it('returns the same order for a repeated Idempotency-Key and refuses misuse', function () {
    $quote = quoteId(Checkout::cart($this->vendor, [[$this->product]], $this->address));

    $first = Checkout::place($this->token, $quote, key: 'same-key')->assertCreated()->json('data.code');
    Checkout::place($this->token, $quote, key: 'same-key')->assertOk()->assertJsonPath('data.code', $first);
    expectApiError(Checkout::place($this->token, $quote, key: 'other-key'), 409, 'quote_used');

    $another = quoteId(Checkout::cart($this->vendor, [[$this->product]], $this->address));
    expectApiError(Checkout::place($this->token, $another, key: 'same-key'), 409, 'idempotency_key_reused');

    expect(Order::query()->count())->toBe(1)->and($this->product->fresh()->stock_qty)->toBe(9);
});

it('needs an Idempotency-Key header', function (?string $key) {
    $quote = quoteId(Checkout::cart($this->vendor, [[$this->product]], $this->address));
    $request = $this->withToken($this->token);
    if ($key !== null) {
        $request = $request->withHeader('Idempotency-Key', $key);
    }

    $error = expectApiError($request->postJson('/api/v1/me/orders', ['quote_id' => $quote, 'payment_method' => 'pay_on_delivery']), 400, 'validation_failed');
    expect($error['fields'])->toHaveKey('idempotency_key');
})->with(['missing' => [null], 'bad characters' => ['no spaces!'], 'too long' => [str_repeat('a', 101)]]);

it('pays from the wallet in the same transaction', function () {
    app(WalletLedger::class)->credit($this->customer, 500000, WalletKind::TopUp, 't1');
    $quote = quoteId(Checkout::cart($this->vendor, [[$this->product]], $this->address));

    Checkout::place($this->token, $quote, 'wallet')->assertCreated()->assertJsonPath('data.payment_status', 'paid');

    $order = Order::query()->sole();
    $debit = WalletTransaction::query()->where('kind', 'order_payment')->sole();
    expect($debit->amount_kobo)->toBe(200000)
        ->and($debit->order_id)->toBe($order->id)
        ->and($debit->reference)->toBe("order:{$order->uuid}:payment")
        ->and(app(WalletLedger::class)->wallet($this->customer)->balance_kobo)->toBe(300000);
});

it('refuses a payment method the quote does not allow', function () {
    $quote = quoteId(Checkout::cart($this->vendor, [[$this->product]], $this->address));

    $error = expectApiError(Checkout::place($this->token, $quote, 'wallet'), 422, 'payment_method_unavailable');
    expect($error['meta']['reason'])->toBe('insufficient_balance');
    expect(expectApiError(Checkout::place($this->token, $quote, 'paystack', 'k2'), 422, 'payment_method_unavailable')['meta']['reason'])->toBe('disabled')
        ->and(Order::query()->count())->toBe(0)
        ->and($this->product->fresh()->stock_qty)->toBe(10);
});

it('creates gateway orders awaiting payment, without showing the handover code', function () {
    config(['wegodeliva.payments.paystack' => true]);
    $quote = quoteId(Checkout::cart($this->vendor, [[$this->product]], $this->address));

    Checkout::place($this->token, $quote, 'paystack')
        ->assertCreated()
        ->assertJsonPath('data.status', 'awaiting_payment')
        ->assertJsonPath('data.handover_code', null)
        ->assertJsonPath('data.placed_at', null);
});

it('returns a fresh quote instead of placing when prices changed', function () {
    $quote = quoteId(Checkout::cart($this->vendor, [[$this->product]], $this->address));
    $this->product->update(['price_kobo' => 160000]);

    $error = expectApiError(Checkout::place($this->token, $quote), 409, 'quote_changed');
    $fresh = $error['meta']['quote'];
    expect($fresh['price']['subtotal']['amount_kobo'])->toBe(160000)
        ->and($fresh['id'])->not->toBe($quote)
        ->and(Order::query()->count())->toBe(0);

    Checkout::place($this->token, $fresh['id'], key: 'tap-2')->assertCreated()->assertJsonPath('data.subtotal.amount_kobo', 160000);
});

it('re-checks the cart at placement', function () {
    $quote = quoteId(Checkout::cart($this->vendor, [[$this->product, 3]], $this->address));
    $this->product->update(['stock_qty' => 2]);

    expectApiError(Checkout::place($this->token, $quote), 422, 'out_of_stock');
    expect(Order::query()->count())->toBe(0);
});

it('takes stock under a lock even if it ran out after the cart was checked', function () {
    $quote = quoteId(Checkout::cart($this->vendor, [[$this->product, 2]], $this->address));
    // Another checkout takes the stock between the re-check and the reservation.
    Event::listen(TransactionBeginning::class, function () {
        static $done = false;
        if (! $done) {
            $done = true;
            DB::table('products')->where('id', $this->product->id)->update(['stock_qty' => 1]);
        }
    });

    $error = expectApiError(Checkout::place($this->token, $quote), 422, 'out_of_stock');
    expect($error['meta']['available'])->toBe(1)
        ->and(Order::query()->count())->toBe(0);
});

it('snapshots the store\'s service fees on the order', function () {
    $fee = Fee::query()->create(['name' => 'Packaging', 'type' => 'fixed', 'value' => 10000, 'beneficiary' => 'vendor']);
    $this->vendor->fees()->attach($fee->id);
    $quote = quoteId(Checkout::cart($this->vendor, [[$this->product]], $this->address));

    Checkout::place($this->token, $quote)
        ->assertCreated()
        ->assertJsonPath('data.fees.0.name', 'Packaging')
        ->assertJsonPath('data.fees.0.amount.amount_kobo', 10000)
        ->assertJsonPath('data.service_fee.amount_kobo', 10000)
        ->assertJsonPath('data.total.amount_kobo', 150000 + 50000 + 10000);

    // Changing the fee later does not change the order.
    $fee->update(['name' => 'Bags', 'value' => 99999]);
    $row = Order::query()->sole()->fees()->sole();
    expect([$row->name, $row->amount_kobo, $row->beneficiary->value, $row->fee_id])->toBe(['Packaging', 10000, 'vendor', $fee->id]);
});

it('records the coupon use with the order', function () {
    Coupon::query()->create(['code' => 'ONCE', 'discount_type' => 'fixed', 'value' => 20000, 'max_uses_per_user' => 1]);
    $quote = quoteId(Checkout::cart($this->vendor, [[$this->product]], $this->address, ['coupon_code' => 'ONCE']));

    Checkout::place($this->token, $quote)->assertCreated()->assertJsonPath('data.discount.amount_kobo', 20000);

    $order = Order::query()->sole();
    expect(CouponRedemption::query()->sole()->only(['order_id', 'user_id', 'discount_kobo']))->toBe(['order_id' => $order->id, 'user_id' => $this->customer->id, 'discount_kobo' => 20000])
        ->and($order->coupon_id)->not->toBeNull()
        ->and($order->items()->sole()->discount_kobo)->toBe(20000);
    $error = expectApiError(Checkout::quote($this->token, Checkout::cart($this->vendor, [[$this->product]], $this->address, ['coupon_code' => 'ONCE'])), 422, 'coupon_invalid');
    expect($error['meta']['reason'])->toBe('customer_limit_reached');
});

it('refuses expired and other people\'s quotes', function () {
    $quote = quoteId(Checkout::cart($this->vendor, [[$this->product]], $this->address));

    $this->app['auth']->forgetGuards();
    expectApiError(Checkout::place(Checkout::token(makeUser()), $quote), 404, 'quote_not_found');
    $this->app['auth']->forgetGuards();
    OrderQuote::query()->update(['expires_at' => now()->subSecond()]);
    expectApiError(Checkout::place($this->token, $quote), 422, 'quote_expired');
});

it('needs a verified email, a confirmed phone and no wallet debt', function (Closure $setup, int $status, string $code) {
    $quote = quoteId(Checkout::cart($this->vendor, [[$this->product]], $this->address));
    $setup($this->customer);
    $this->app['auth']->forgetGuards();

    expectApiError(Checkout::place($this->token, $quote), $status, $code);
    expect(Order::query()->count())->toBe(0);
})->with([
    'unverified email' => [fn ($u) => $u->forceFill(['email_verified_at' => null])->save(), 403, 'email_not_verified'],
    'phone to confirm' => [fn ($u) => $u->forceFill(['phone_confirmation_required' => true])->save(), 403, 'phone_confirmation_required'],
    'wallet debt' => [fn ($u) => app(WalletLedger::class)->adjust($u, $u, -100, 'Debt', 'd1'), 422, 'wallet_negative'],
]);

it('accepts orders straight away for auto-accept stores', function () {
    $this->vendor->update(['auto_accept_orders' => true, 'prep_time_minutes' => 25]);
    $unlimited = Checkout::product($this->vendor, ['stock_qty' => null]);
    $quote = quoteId(Checkout::cart($this->vendor, [[$unlimited, 3]], $this->address));

    Checkout::place($this->token, $quote)->assertCreated()->assertJsonPath('data.status', 'accepted');

    $order = Order::query()->sole();
    expect($order->items()->sole()->stock_reserved)->toBeFalse()
        ->and($unlimited->fresh()->stock_qty)->toBeNull()
        ->and($order->prep_time_minutes)->toBe(25)
        ->and($order->accepted_at)->not->toBeNull()
        ->and($order->history()->pluck('to_status')->map->value->all())->toBe(['placed', 'accepted'])
        ->and($order->history()->get()[1]->actor_role->value)->toBe('system');
});

it('lists the customer\'s own orders, active or past', function () {
    $active = Checkout::place($this->token, quoteId(Checkout::cart($this->vendor, [[$this->product]], $this->address)), key: 'a')->json('data.code');
    $past = Checkout::place($this->token, quoteId(Checkout::cart($this->vendor, [[$this->product]], $this->address)), key: 'b')->json('data.code');
    $this->withToken($this->token)->postJson("/api/v1/me/orders/{$past}/cancel")->assertOk();
    $other = makeUser();

    $this->withToken($this->token)->getJson('/api/v1/me/orders')->assertOk()->assertJsonPath('data.*.code', [$past, $active]);
    $this->withToken($this->token)->getJson('/api/v1/me/orders?state=active')->assertJsonPath('data.*.code', [$active]);
    $this->withToken($this->token)->getJson('/api/v1/me/orders?state=past')->assertJsonPath('data.*.code', [$past]);
    $this->withToken($this->token)->getJson('/api/v1/me/orders/'.strtolower($active))->assertOk()->assertJsonPath('data.code', $active);

    $this->app['auth']->forgetGuards();
    expectApiError($this->withToken(Checkout::token($other))->getJson("/api/v1/me/orders/{$active}"), 404, 'not_found');
    $this->withToken(Checkout::token($other))->getJson('/api/v1/me/orders')->assertOk()->assertJsonPath('data', []);
    expect(Product::query()->count())->toBe(1);
});
