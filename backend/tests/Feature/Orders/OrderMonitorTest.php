<?php

use App\Enums\ActorRole;
use App\Enums\Fault;
use App\Enums\OrderStatus;
use App\Enums\WalletKind;
use App\Exceptions\ApiException;
use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Services\Ledger\WalletLedger;
use App\Services\Orders\OrderFulfilment;
use App\Services\Orders\OrderStateMachine;
use App\Services\Orders\Refunds;
use Database\Seeders\CitySeeder;
use Database\Seeders\VendorTypeSeeder;
use Tests\Support\Checkout;

beforeEach(function () {
    $this->seed([VendorTypeSeeder::class, CitySeeder::class]);
    $this->customer = makeUser();
    $this->token = Checkout::token($this->customer);
    $this->vendor = Checkout::vendor();
    $this->product = Checkout::product($this->vendor);
    $this->address = Checkout::address($this->customer);
});

function monitoredOrder(string $key, string $method = 'pay_on_delivery'): Order
{
    $t = test();
    app('auth')->forgetGuards();
    $quote = Checkout::quote($t->token, Checkout::cart($t->vendor, [[$t->product]], $t->address))->json('data.id');
    app('auth')->forgetGuards();

    return Order::query()->where('code', Checkout::place($t->token, $quote, $method, $key)->json('data.code'))->sole();
}

it('alerts once when a store is slow and cancels with a full refund when it never accepts', function () {
    app(WalletLedger::class)->credit($this->customer, 500000, WalletKind::TopUp, 't');
    $slow = monitoredOrder('a', 'wallet');
    $silent = monitoredOrder('b');
    $fresh = monitoredOrder('c');
    Order::query()->whereKey($slow->id)->update(['placed_at' => now()->subMinutes(6)]);
    Order::query()->whereKey($silent->id)->update(['placed_at' => now()->subMinutes(11)]);

    $this->artisan('orders:monitor')->expectsOutput('Timed out 1, store alerts 1, rider alerts 0, stale alerts 0.')->assertSuccessful();
    $this->artisan('orders:monitor')->expectsOutput('Timed out 0, store alerts 0, rider alerts 0, stale alerts 0.');

    expect($silent->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($silent->fresh()->fault)->toBe(Fault::Vendor)
        ->and($slow->fresh()->vendor_alerted_at)->not->toBeNull()
        ->and($fresh->fresh()->vendor_alerted_at)->toBeNull()
        ->and(AdminAuditLog::query()->pluck('action')->sort()->values()->all())->toBe(['order.vendor_slow', 'order.vendor_timeout']);

    Order::query()->whereKey($slow->id)->update(['placed_at' => now()->subMinutes(11)]);
    $this->artisan('orders:monitor');
    expect($slow->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(app(WalletLedger::class)->wallet($this->customer)->balance_kobo)->toBe(500000);
});

it('escalates delivery orders that have no rider, and flags orders stuck for a day', function () {
    $order = monitoredOrder('a');
    app(OrderStateMachine::class)->transition($order, OrderStatus::Accepted, ActorRole::Vendor);
    Order::query()->whereKey($order->id)->update(['accepted_at' => now()->subMinutes(11)]);

    $this->artisan('orders:monitor')->expectsOutput('Timed out 0, store alerts 0, rider alerts 1, stale alerts 0.');
    $this->artisan('orders:monitor')->expectsOutput('Timed out 0, store alerts 0, rider alerts 0, stale alerts 0.');

    Order::query()->whereKey($order->id)->update(['accepted_at' => now()->subMinutes(31), 'created_at' => now()->subHours(25)]);
    $this->artisan('orders:monitor')->expectsOutput('Timed out 0, store alerts 0, rider alerts 1, stale alerts 1.');

    expect($order->fresh()->rider_escalation_level)->toBe(2)
        ->and(AdminAuditLog::query()->pluck('action')->sort()->values()->all())->toBe(['order.no_rider', 'order.no_rider_escalated', 'order.stale']);
});

it('refuses deliveries in the wrong status and refunds that do not add up', function () {
    $order = monitoredOrder('a', 'pay_on_delivery');
    $fulfilment = app(OrderFulfilment::class);
    $rider = makeUser();

    expect(fn () => $fulfilment->riderDeliver($order, $rider, $order->handover_code, 'cash'))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode)->toBe('order_status_conflict'));
    expect(fn () => $fulfilment->unassign($order, $rider, 'No rider'))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode)->toBe('order_status_conflict'));

    $refunds = app(Refunds::class);
    expect(fn () => $refunds->refund($order, 0, 'x', Fault::None, null, 'r0'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $refunds->refund($order, 100, 'x', Fault::None, null, 'r1'))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode)->toBe('refund_exceeds_paid'));

    app(WalletLedger::class)->credit($this->customer, 500000, WalletKind::TopUp, 't');
    $paid = monitoredOrder('b', 'wallet');
    $refunds->refund($paid, 1000, 'Missing drink', Fault::Vendor, null, 'r2');
    expect(fn () => $refunds->refund($paid, 2000, 'Missing drink', Fault::Vendor, null, 'r2'))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode)->toBe('conflict'))
        ->and($refunds->refund($paid, 1000, 'Missing drink', Fault::Vendor, null, 'r2')->amount_kobo)->toBe(1000);
});
