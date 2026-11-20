<?php

use App\Enums\ActorRole;
use App\Enums\Fault;
use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Enums\WalletKind;
use App\Exceptions\ApiException;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Services\Ledger\WalletLedger;
use App\Services\Orders\OrderLifecycle;
use App\Services\Orders\OrderStateMachine;
use Database\Seeders\CitySeeder;
use Database\Seeders\VendorTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Checkout;

beforeEach(function () {
    $this->seed([VendorTypeSeeder::class, CitySeeder::class]);
    $this->customer = makeUser();
    $this->token = Checkout::token($this->customer);
    $this->vendor = Checkout::vendor();
    $this->product = Checkout::product($this->vendor, ['stock_qty' => 5]);
    $this->address = Checkout::address($this->customer);
    $this->machine = app(OrderStateMachine::class);
});

/** Places an order through the API and returns it. */
function placeOrder(array $extra = [], string $method = 'pay_on_delivery', string $key = 'k1'): Order
{
    $t = test();
    $quote = Checkout::quote($t->token, Checkout::cart($t->vendor, [[$t->product, 2]], $t->address, $extra))->assertCreated()->json('data.id');
    $code = Checkout::place($t->token, $quote, $method, $key)->assertCreated()->json('data.code');

    return Order::query()->where('code', $code)->sole();
}

it('lets the customer cancel before the store accepts, returning stock and the coupon use', function () {
    Coupon::query()->create(['code' => 'SAVE', 'discount_type' => 'fixed', 'value' => 10000, 'max_uses_per_user' => 1]);
    $order = placeOrder(['coupon_code' => 'SAVE']);
    expect($this->product->fresh()->stock_qty)->toBe(3);

    $this->withToken($this->token)->postJson("/api/v1/me/orders/{$order->code}/cancel", ['reason' => 'Ordered by mistake'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancel_reason', 'Ordered by mistake')
        ->assertJsonPath('data.can_cancel', false)
        ->assertJsonPath('data.handover_code', null)
        ->assertJsonPath('data.payment_status', 'unpaid');

    $order->refresh();
    expect($this->product->fresh()->stock_qty)->toBe(5)
        ->and($order->items()->sole()->stock_reserved)->toBeFalse()
        ->and($order->fault)->toBe(Fault::Customer)
        ->and($order->cancelled_by)->toBe('customer')
        ->and($order->cancelled_at)->not->toBeNull()
        ->and(CouponRedemption::query()->sole()->released_at)->not->toBeNull()
        ->and($order->history()->pluck('to_status')->map->value->all())->toBe(['placed', 'cancelled']);

    // The released coupon can be used again.
    Checkout::quote($this->token, Checkout::cart($this->vendor, [[$this->product]], $this->address, ['coupon_code' => 'SAVE']))->assertCreated();
});

it('refunds wallet payments in full to the wallet when cancelled, even above the balance cap', function () {
    $wallets = app(WalletLedger::class);
    $wallets->credit($this->customer, 400000, WalletKind::TopUp, 't1');
    $order = placeOrder(method: 'wallet');
    $wallets->adjust($this->customer, $this->customer, 30_000_000, 'Fill to the cap', 'fill');

    $this->withToken($this->token)->postJson("/api/v1/me/orders/{$order->code}/cancel")->assertOk()->assertJsonPath('data.payment_status', 'refunded');

    $refund = $wallets->wallet($this->customer)->transactions()->where('kind', 'refund')->sole();
    expect($refund->amount_kobo)->toBe($order->total_kobo)
        ->and($refund->reference)->toBe("order:{$order->uuid}:refund")
        ->and($refund->note)->toBe("Refund for order {$order->code}")
        ->and($wallets->wallet($this->customer)->balance_kobo)->toBe(400000 + 30_000_000);
});

it('does not let the customer cancel once the store has accepted', function () {
    $order = placeOrder();
    $this->machine->transition($order, OrderStatus::Accepted, ActorRole::Vendor);

    $error = expectApiError($this->withToken($this->token)->postJson("/api/v1/me/orders/{$order->code}/cancel"), 409, 'order_not_cancellable');
    expect($error['meta']['status'])->toBe('accepted')->and($this->product->fresh()->stock_qty)->toBe(3);
});

it('releases scheduled orders to the store at scheduled time minus prep time and buffer', function () {
    Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00', 'Africa/Lagos'));
    $order = placeOrder(['scheduled_for' => '2026-12-01T13:00:00+01:00']);
    $other = placeOrder(['scheduled_for' => '2026-12-01T13:00:00+01:00'], key: 'k2');
    $other->forceFill(['vendor_id' => Checkout::vendor(['auto_accept_orders' => true])->id])->save();

    expect($order->status)->toBe(OrderStatus::Scheduled)
        ->and($order->placed_at)->toBeNull()
        // 13:00 − 20 min prep − 10 min buffer = 12:30 Lagos.
        ->and($order->release_at->toIso8601String())->toBe('2026-12-01T11:30:00+00:00');

    Carbon::setTestNow(Carbon::parse('2026-12-01 12:29:59', 'Africa/Lagos'));
    $this->artisan('orders:release-scheduled')->expectsOutput('Released 0 scheduled order(s).')->assertSuccessful();

    Carbon::setTestNow(Carbon::parse('2026-12-01 12:30:00', 'Africa/Lagos'));
    $this->artisan('orders:release-scheduled')->expectsOutput('Released 2 scheduled order(s).')->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Placed)
        ->and($order->fresh()->placed_at)->not->toBeNull()
        ->and($other->fresh()->status)->toBe(OrderStatus::Accepted)
        ->and($order->history()->get()->last()->reason)->toBe('Scheduled time reached');
    Carbon::setTestNow();
});

it('follows the transition table for each actor', function (OrderStatus $from, OrderStatus $to, ActorRole $actor, bool $allowed) {
    expect($this->machine->allows($from, $to, $actor))->toBe($allowed);
})->with([
    [OrderStatus::AwaitingPayment, OrderStatus::Placed, ActorRole::System, true],
    [OrderStatus::AwaitingPayment, OrderStatus::Placed, ActorRole::Customer, false],
    [OrderStatus::AwaitingPayment, OrderStatus::Cancelled, ActorRole::Customer, true],
    [OrderStatus::AwaitingReview, OrderStatus::AwaitingPayment, ActorRole::Vendor, true],
    [OrderStatus::Scheduled, OrderStatus::Cancelled, ActorRole::Vendor, true],
    [OrderStatus::Scheduled, OrderStatus::Placed, ActorRole::Vendor, false],
    [OrderStatus::Placed, OrderStatus::Accepted, ActorRole::Vendor, true],
    [OrderStatus::Placed, OrderStatus::Accepted, ActorRole::Rider, false],
    [OrderStatus::Accepted, OrderStatus::Cancelled, ActorRole::Customer, false],
    [OrderStatus::Accepted, OrderStatus::Ready, ActorRole::Vendor, true],
    [OrderStatus::Ready, OrderStatus::PickedUp, ActorRole::Rider, true],
    [OrderStatus::Ready, OrderStatus::Delivered, ActorRole::Vendor, true],
    [OrderStatus::Ready, OrderStatus::Delivered, ActorRole::Rider, false],
    [OrderStatus::PickedUp, OrderStatus::Delivered, ActorRole::Rider, true],
    [OrderStatus::PickedUp, OrderStatus::Failed, ActorRole::Rider, true],
    [OrderStatus::PickedUp, OrderStatus::Cancelled, ActorRole::Rider, false],
    'admins cancel anything not final' => [OrderStatus::PickedUp, OrderStatus::Cancelled, ActorRole::Admin, true],
    'admins fail anything not final' => [OrderStatus::Accepted, OrderStatus::Failed, ActorRole::Admin, true],
    'admins cannot reopen' => [OrderStatus::Delivered, OrderStatus::Cancelled, ActorRole::Admin, false],
    'final stays final' => [OrderStatus::Cancelled, OrderStatus::Placed, ActorRole::System, false],
    'no skipping' => [OrderStatus::Placed, OrderStatus::Delivered, ActorRole::Admin, false],
]);

it('refuses transitions the table does not allow, with the current status', function () {
    $order = placeOrder();

    try {
        $this->machine->transition($order, OrderStatus::Ready, ActorRole::Vendor);
        $this->fail('Expected a conflict.');
    } catch (ApiException $e) {
        expect($e->errorCode)->toBe('order_status_conflict')->and($e->status)->toBe(409)->and($e->meta)->toBe(['status' => 'placed']);
    }
    expect($order->fresh()->status)->toBe(OrderStatus::Placed)->and(OrderStatusHistory::query()->count())->toBe(1);
});

it('records who moved the order, and when, at every step', function () {
    $order = placeOrder();
    $admin = makeUser(RoleName::Admin);

    $this->machine->transition($order, OrderStatus::Accepted, ActorRole::Vendor);
    $this->machine->transition($order, OrderStatus::Ready, ActorRole::Vendor);
    $this->machine->transition($order, OrderStatus::PickedUp, ActorRole::Rider);
    $this->machine->transition($order, OrderStatus::Failed, ActorRole::Admin, $admin, 'Customer unreachable', ['fault' => Fault::Customer]);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Failed)
        ->and($order->fault)->toBe(Fault::Customer)
        ->and([$order->accepted_at, $order->ready_at, $order->picked_up_at, $order->failed_at])->each->not->toBeNull();
    $last = $order->history()->get()->last();
    expect($last->from_status)->toBe(OrderStatus::PickedUp)
        ->and($last->actor_user_id)->toBe($admin->id)
        ->and($last->reason)->toBe('Customer unreachable')
        ->and($order->history()->count())->toBe(5);
    expect(OrderStatus::Scheduled->timestampColumn())->toBeNull();

    expect(fn () => DB::statement('DELETE FROM order_status_history'))->toThrow(QueryException::class, 'order_status_history is append-only');
});

it('lets the system cancel through the lifecycle too', function () {
    $order = placeOrder();

    app(OrderLifecycle::class)->cancel($order, ActorRole::System, null, 'Store did not respond', Fault::Vendor);

    expect($order->fresh()->only(['cancelled_by', 'cancel_reason']))->toBe(['cancelled_by' => 'system', 'cancel_reason' => 'Store did not respond'])
        ->and($order->history()->get()->last()->actor_user_id)->toBeNull();
});
