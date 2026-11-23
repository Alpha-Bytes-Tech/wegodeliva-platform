<?php

use App\Enums\ClientApp;
use App\Enums\EarningsOwner;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Enums\WalletKind;
use App\Models\AdminAuditLog;
use App\Models\CommissionRecord;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Ledger\EarningsLedger;
use App\Services\Ledger\WalletLedger;
use App\Services\Orders\DeliverySettlement;
use Database\Seeders\CitySeeder;
use Database\Seeders\VendorTypeSeeder;
use Illuminate\Support\Str;
use Tests\Support\Checkout;

beforeEach(function () {
    $this->seed([VendorTypeSeeder::class, CitySeeder::class]);
    $this->customer = makeUser();
    $this->token = Checkout::token($this->customer);
    $this->vendor = Checkout::vendor();
    $this->product = Checkout::product($this->vendor, ['stock_qty' => 10]);
    $this->address = Checkout::address($this->customer);
    $this->staff = makeUser(RoleName::VendorStaff);
    $this->vendor->members()->attach($this->staff->id, ['role' => 'staff']);
    $this->admin = makeUser(RoleName::Admin);
    $this->earnings = app(EarningsLedger::class);
});

/** Places an order (₦1,500 item + ₦500 delivery unless pickup) and returns it. */
function fulfilOrder(string $method = 'pay_on_delivery', bool $pickup = false, string $key = 'k1'): Order
{
    $t = test();
    app('auth')->forgetGuards();
    if ($method === 'wallet') {
        app(WalletLedger::class)->credit($t->customer, 1_000_000, WalletKind::TopUp, "top-{$key}");
    }
    $quote = Checkout::quote($t->token, Checkout::cart($t->vendor, [[$t->product]], $pickup ? null : $t->address))->assertCreated()->json('data.id');
    app('auth')->forgetGuards();
    $code = Checkout::place($t->token, $quote, $method, $key)->assertCreated()->json('data.code');
    app('auth')->forgetGuards();

    return Order::query()->where('code', $code)->sole();
}

/** Calls an endpoint as the given user and app, with a fresh sign-in. */
function asUser(User $user, ClientApp $app)
{
    app('auth')->forgetGuards();

    return test()->withToken(tokenFor($user, $app));
}

function rider(?Vendor $ownedBy = null): User
{
    $rider = makeUser();
    asUser(test()->admin, ClientApp::Admin)->postJson('/api/v1/admin/riders', array_filter([
        'user_id' => $rider->uuid,
        'rider_type' => $ownedBy === null ? 'independent' : 'vendor_owned',
        'vendor_id' => $ownedBy?->uuid,
    ]))->assertCreated();

    return $rider->fresh();
}

function vendorUrl(Order $order, string $action = ''): string
{
    return "/api/v1/vendor/stores/{$order->vendor->uuid}/orders/{$order->code}".($action === '' ? '' : "/{$action}");
}

it('takes an order through the store: inbox, accept with prep time, ready', function () {
    $order = fulfilOrder();
    $staff = fn () => asUser($this->staff, ClientApp::Vendor);

    $staff()->getJson("/api/v1/vendor/stores/{$this->vendor->uuid}/orders?tab=new")->assertOk()
        ->assertJsonPath('data.0.code', $order->code)
        ->assertJsonPath('data.0.customer.phone', null)
        ->assertJsonPath('data.0.address.landmark', null)
        ->assertJsonPath('data.0.collect.amount_kobo', 200000);

    $staff()->postJson(vendorUrl($order, 'accept'), ['prep_time_minutes' => 25])->assertOk()
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.prep_time_minutes', 25);
    $staff()->postJson(vendorUrl($order, 'ready'))->assertOk()->assertJsonPath('data.status', 'ready');
    $staff()->getJson("/api/v1/vendor/stores/{$this->vendor->uuid}/orders?tab=ready")->assertJsonPath('data.*.code', [$order->code]);
    $staff()->getJson(vendorUrl($order))->assertOk()->assertJsonPath('data.status', 'ready');

    expectApiError($staff()->postJson(vendorUrl($order, 'accept'), ['prep_time_minutes' => 4]), 400, 'validation_failed');
    expectApiError(asUser(makeUser(RoleName::VendorOwner), ClientApp::Vendor)->getJson(vendorUrl($order)), 404, 'not_found');
});

it('keeps orders waiting for payment out of the store\'s sight', function () {
    config(['wegodeliva.payments.paystack' => true]);
    $order = fulfilOrder('paystack');

    expectApiError(asUser($this->staff, ClientApp::Vendor)->getJson(vendorUrl($order)), 404, 'not_found');
    asUser($this->staff, ClientApp::Vendor)->getJson("/api/v1/vendor/stores/{$this->vendor->uuid}/orders")->assertJsonPath('data', []);
});

it('refunds the customer in full when the store rejects or cancels', function () {
    $order = fulfilOrder('wallet');

    asUser($this->staff, ClientApp::Vendor)->postJson(vendorUrl($order, 'reject'), ['reason' => 'Out of rice'])->assertOk()->assertJsonPath('data.status', 'cancelled');

    $order->refresh();
    expect($order->fault->value)->toBe('vendor')
        ->and($order->payment_status)->toBe(PaymentStatus::Refunded)
        ->and(Refund::query()->sole()->only(['amount_kobo', 'reason']))->toBe(['amount_kobo' => 200000, 'reason' => 'Out of rice'])
        ->and(app(WalletLedger::class)->wallet($this->customer)->balance_kobo)->toBe(1_000_000);
});

it('hands a pickup order over against the customer\'s code and settles earnings', function () {
    $order = fulfilOrder(pickup: true);
    $staff = fn () => asUser($this->staff, ClientApp::Vendor);
    $staff()->postJson(vendorUrl($order, 'accept'), ['prep_time_minutes' => 10]);
    $staff()->postJson(vendorUrl($order, 'ready'));

    expectApiError($staff()->postJson(vendorUrl($order, 'handover'), ['handover_code' => $order->handover_code]), 400, 'validation_failed');
    $staff()->postJson(vendorUrl($order, 'handover'), ['handover_code' => $order->handover_code, 'collected_via' => 'cash'])
        ->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.payment_status', 'paid');

    $vendor = $this->earnings->account(EarningsOwner::Vendor, $this->vendor->id);
    // The store holds the ₦1,500 cash and earned ₦1,500 less 5% commission and its VAT.
    expect([$vendor->balance_kobo, $vendor->commission_due_kobo, $vendor->commission_vat_due_kobo, $vendor->cash_owed_kobo])->toBe([150000, 7500, 563, 150000])
        ->and(CommissionRecord::query()->sole()->only(['delivery_gross_kobo', 'delivery_to_vendor', 'platform_revenue_kobo']))->toBe(['delivery_gross_kobo' => 0, 'delivery_to_vendor' => true, 'platform_revenue_kobo' => 7500])
        ->and($order->fresh()->collected_via)->toBe('cash');

    $delivery = fulfilOrder(key: 'k2');
    expectApiError(asUser($this->staff, ClientApp::Vendor)->postJson(vendorUrl($delivery, 'handover'), ['handover_code' => '0000']), 409, 'order_status_conflict');
});

it('delivers with a rider: assignment, pickup, code, cash and earnings', function () {
    $order = fulfilOrder();
    $rider = rider();
    asUser($this->staff, ClientApp::Vendor)->postJson(vendorUrl($order, 'accept'), ['prep_time_minutes' => 15]);

    asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$order->code}/assign-rider", ['rider_id' => $rider->uuid])
        ->assertOk()->assertJsonPath('data.rider.id', $rider->uuid);
    asUser($this->staff, ClientApp::Vendor)->postJson(vendorUrl($order, 'ready'));

    $riderApi = fn () => asUser($rider, ClientApp::Rider);
    $riderApi()->getJson('/api/v1/rider/jobs')->assertOk()
        ->assertJsonPath('data.0.code', $order->code)
        ->assertJsonPath('data.0.address.landmark', 'Opposite the mosque')
        ->assertJsonPath('data.0.customer.phone', $this->customer->phone)
        ->assertJsonMissingPath('data.0.handover_code');
    $riderApi()->postJson("/api/v1/rider/jobs/{$order->code}/picked-up")->assertOk()->assertJsonPath('data.status', 'picked_up');

    $wrong = $order->handover_code === '1111' ? '2222' : '1111';
    $error = expectApiError($riderApi()->postJson("/api/v1/rider/jobs/{$order->code}/deliver", ['handover_code' => $wrong, 'collected_via' => 'transfer']), 422, 'handover_code_invalid');
    expect($error['meta']['attempts_left'])->toBe(2);

    $riderApi()->postJson("/api/v1/rider/jobs/{$order->code}/deliver", ['handover_code' => $order->handover_code, 'collected_via' => 'transfer'])
        ->assertOk()->assertJsonPath('data.status', 'delivered');

    $riderAccount = $this->earnings->account(EarningsOwner::Rider, $rider->id);
    $vendorAccount = $this->earnings->account(EarningsOwner::Vendor, $this->vendor->id);
    expect([$riderAccount->balance_kobo, $riderAccount->commission_due_kobo, $riderAccount->commission_vat_due_kobo, $riderAccount->cash_owed_kobo])->toBe([50000, 1500, 113, 200000])
        ->and([$vendorAccount->balance_kobo, $vendorAccount->cash_owed_kobo])->toBe([150000, 0])
        ->and(CommissionRecord::query()->sole()->platform_revenue_kobo)->toBe(7500 + 1500)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
    $riderApi()->getJson('/api/v1/rider/jobs?state=past')->assertJsonPath('data.*.code', [$order->code]);

    // Settling again changes nothing.
    app(DeliverySettlement::class)->settle($order->fresh());
    expect(CommissionRecord::query()->count())->toBe(1)->and($riderAccount->fresh()->balance_kobo)->toBe(50000);
});

it('locks the code after three wrong tries until support completes the delivery', function () {
    $order = fulfilOrder('wallet');
    $rider = rider();
    asUser($this->staff, ClientApp::Vendor)->postJson(vendorUrl($order, 'accept'), ['prep_time_minutes' => 15]);
    asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$order->code}/assign-rider", ['rider_id' => $rider->uuid]);
    asUser($this->staff, ClientApp::Vendor)->postJson(vendorUrl($order, 'ready'));
    asUser($rider, ClientApp::Rider)->postJson("/api/v1/rider/jobs/{$order->code}/picked-up");
    $wrong = $order->handover_code === '1111' ? '2222' : '1111';

    expectApiError(asUser($rider, ClientApp::Rider)->postJson("/api/v1/rider/jobs/{$order->code}/deliver", ['handover_code' => $wrong]), 422, 'handover_code_invalid');
    expectApiError(asUser($rider, ClientApp::Rider)->postJson("/api/v1/rider/jobs/{$order->code}/deliver", ['handover_code' => $wrong]), 422, 'handover_code_invalid');
    expectApiError(asUser($rider, ClientApp::Rider)->postJson("/api/v1/rider/jobs/{$order->code}/deliver", ['handover_code' => $wrong]), 423, 'handover_locked');
    expectApiError(asUser($rider, ClientApp::Rider)->postJson("/api/v1/rider/jobs/{$order->code}/deliver", ['handover_code' => $order->handover_code]), 423, 'handover_locked');

    asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$order->code}/deliver", ['reason' => 'Customer verified by phone'])
        ->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.handover_attempts', 3);
    expect(AdminAuditLog::query()->where('action', 'order.delivered_override')->count())->toBe(1)
        ->and(CommissionRecord::query()->count())->toBe(1);
});

it('sends delivery earnings to the store for its own riders, and keeps them to that store', function () {
    $order = fulfilOrder();
    $own = rider($this->vendor);
    $elsewhere = rider(Checkout::vendor());
    asUser($this->staff, ClientApp::Vendor)->postJson(vendorUrl($order, 'accept'), ['prep_time_minutes' => 15]);

    expectApiError(asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$order->code}/assign-rider", ['rider_id' => $elsewhere->uuid]), 422, 'rider_not_eligible');
    expectApiError(asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$order->code}/assign-rider", ['rider_id' => makeUser()->uuid]), 422, 'rider_not_eligible');

    asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$order->code}/assign-rider", ['rider_id' => $own->uuid])->assertOk();
    asUser($this->staff, ClientApp::Vendor)->postJson(vendorUrl($order, 'ready'));
    asUser($own, ClientApp::Rider)->postJson("/api/v1/rider/jobs/{$order->code}/picked-up");
    asUser($own, ClientApp::Rider)->postJson("/api/v1/rider/jobs/{$order->code}/deliver", ['handover_code' => $order->handover_code, 'collected_via' => 'cash'])->assertOk();

    $vendorAccount = $this->earnings->account(EarningsOwner::Vendor, $this->vendor->id);
    expect([$vendorAccount->balance_kobo, $vendorAccount->cash_owed_kobo])->toBe([200000, 200000])
        ->and($this->earnings->account(EarningsOwner::Rider, $own->id)->balance_kobo)->toBe(0)
        ->and(CommissionRecord::query()->sole()->delivery_to_vendor)->toBeTrue();
});

it('limits riders to their job capacity and lets admins unassign before pickup only', function () {
    $first = fulfilOrder();
    $second = fulfilOrder(key: 'k2');
    $rider = rider();
    foreach ([$first, $second] as $o) {
        asUser($this->staff, ClientApp::Vendor)->postJson(vendorUrl($o, 'accept'), ['prep_time_minutes' => 15]);
    }
    asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$first->code}/assign-rider", ['rider_id' => $rider->uuid])->assertOk();
    expectApiError(asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$second->code}/assign-rider", ['rider_id' => $rider->uuid]), 409, 'rider_busy');

    asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$first->code}/unassign-rider", ['reason' => 'Bike broke down'])
        ->assertOk()->assertJsonPath('data.rider', null);
    expectApiError(asUser($rider, ClientApp::Rider)->getJson("/api/v1/rider/jobs/{$first->code}"), 404, 'not_found');
    expectApiError(asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$first->code}/unassign-rider", ['reason' => 'Again']), 409, 'order_status_conflict');

    asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$second->code}/assign-rider", ['rider_id' => $rider->uuid])->assertOk();
    asUser($this->staff, ClientApp::Vendor)->postJson(vendorUrl($second, 'ready'));
    asUser($rider, ClientApp::Rider)->postJson("/api/v1/rider/jobs/{$second->code}/picked-up");
    expectApiError(asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$second->code}/unassign-rider", ['reason' => 'Too late']), 409, 'order_status_conflict');

    $pickup = fulfilOrder(pickup: true, key: 'k3');
    expectApiError(asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$pickup->code}/assign-rider", ['rider_id' => $rider->uuid]), 409, 'order_status_conflict');
    expect(AdminAuditLog::query()->whereIn('action', ['order.rider_assigned', 'order.rider_unassigned'])->count())->toBe(3);
});

it('records failed deliveries for review, and admins refund with any earnings reversal', function () {
    $order = fulfilOrder('wallet');
    $rider = rider();
    asUser($this->staff, ClientApp::Vendor)->postJson(vendorUrl($order, 'accept'), ['prep_time_minutes' => 15]);
    asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$order->code}/assign-rider", ['rider_id' => $rider->uuid]);
    asUser($this->staff, ClientApp::Vendor)->postJson(vendorUrl($order, 'ready'));
    asUser($rider, ClientApp::Rider)->postJson("/api/v1/rider/jobs/{$order->code}/picked-up");

    $fail = fn (array $body) => asUser($rider, ClientApp::Rider)->postJson("/api/v1/rider/jobs/{$order->code}/fail", $body);
    expectApiError($fail(['reason' => 'No answer', 'photo_path' => 'orders/other/x.jpg', 'customer_called' => true]), 400, 'validation_failed');
    expectApiError($fail(['reason' => 'No answer', 'photo_path' => "orders/{$order->uuid}/door.jpg", 'customer_called' => false]), 400, 'validation_failed');
    $fail(['reason' => 'Customer not answering', 'photo_path' => "orders/{$order->uuid}/door.jpg", 'customer_called' => true])
        ->assertOk()->assertJsonPath('data.status', 'failed');
    expect($order->fresh()->only(['failure_photo_path', 'customer_called']))->toBe(['failure_photo_path' => "orders/{$order->uuid}/door.jpg", 'customer_called' => true])
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);

    $key = (string) Str::uuid();
    $refund = fn (array $body) => asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$order->code}/refunds", $body + ['reason' => 'Items not delivered', 'fault' => 'rider', 'idempotency_key' => $key]);
    expectApiError($refund(['amount_kobo' => 200001]), 422, 'refund_exceeds_paid');
    $refund(['amount_kobo' => 150000, 'reverse_rider_kobo' => 2000])->assertOk()
        ->assertJsonPath('data.payment_status', 'partially_refunded')
        ->assertJsonPath('data.refundable.amount_kobo', 50000);
    $refund(['amount_kobo' => 150000, 'reverse_rider_kobo' => 2000])->assertOk();

    expect(Refund::query()->count())->toBe(1)
        ->and($this->earnings->account(EarningsOwner::Rider, $rider->id)->balance_kobo)->toBe(-2000)
        ->and(AdminAuditLog::query()->where('action', 'order.refunded')->count())->toBe(1);

    asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$order->code}/refunds", ['amount_kobo' => 50000, 'reason' => 'Rest', 'fault' => 'vendor', 'reverse_vendor_kobo' => 1000, 'idempotency_key' => (string) Str::uuid()])
        ->assertOk()->assertJsonPath('data.payment_status', 'refunded');
    expect($this->earnings->account(EarningsOwner::Vendor, $this->vendor->id)->balance_kobo)->toBe(-1000);
});

it('lets admins cancel with a customer cancellation fee paid to the store', function () {
    $order = fulfilOrder('wallet');
    asUser($this->staff, ClientApp::Vendor)->postJson(vendorUrl($order, 'accept'), ['prep_time_minutes' => 15]);

    asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$order->code}/cancel", ['reason' => 'Customer changed their mind', 'fault' => 'customer', 'cancellation_fee_kobo' => 75000])
        ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.fault', 'customer');

    $vendor = $this->earnings->account(EarningsOwner::Vendor, $this->vendor->id);
    expect(Refund::query()->sole()->amount_kobo)->toBe(125000)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and([$vendor->balance_kobo, $vendor->commission_due_kobo])->toBe([75000, 3750]);

    // A fee only applies when the customer is at fault.
    $other = fulfilOrder('wallet', key: 'k2');
    asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$other->code}/cancel", ['reason' => 'Store closed early', 'fault' => 'vendor', 'cancellation_fee_kobo' => 75000])->assertOk();
    expect(Refund::query()->where('order_id', $other->id)->sole()->amount_kobo)->toBe(200000);
});

it('lets admins fail an order with a fault, list and view orders', function () {
    $order = fulfilOrder();
    $done = fulfilOrder(key: 'k2');
    asUser($this->staff, ClientApp::Vendor)->postJson(vendorUrl($order, 'accept'), ['prep_time_minutes' => 15]);

    asUser($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$order->code}/fail", ['reason' => 'Lost in transit', 'fault' => 'platform'])
        ->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.fault', 'platform');

    asUser($this->admin, ClientApp::Admin)->getJson('/api/v1/admin/orders?status=failed')->assertOk()->assertJsonPath('data.*.code', [$order->code]);
    asUser($this->admin, ClientApp::Admin)->getJson('/api/v1/admin/orders?unassigned=1&q='.strtolower($done->code))->assertJsonPath('data.*.code', [$done->code]);
    asUser($this->admin, ClientApp::Admin)->getJson("/api/v1/admin/orders/{$order->code}")->assertOk()
        ->assertJsonPath('data.history.2.reason', 'Lost in transit')
        ->assertJsonPath('data.customer.phone', $this->customer->phone);
    asUser($this->admin, ClientApp::Admin)->getJson('/api/v1/admin/riders')->assertOk();
    expectApiError(asUser($this->admin, ClientApp::Admin)->getJson('/api/v1/admin/orders/NOPE1234'), 404, 'not_found');
});

it('keeps order operations away from non-admins', function () {
    $order = fulfilOrder();
    $zoneAdmin = makeUser(RoleName::ZoneAdmin);

    expectApiError(asUser($zoneAdmin, ClientApp::Admin)->getJson('/api/v1/admin/orders'), 403, 'forbidden');
    expectApiError(asUser($zoneAdmin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$order->code}/cancel", ['reason' => 'x x x', 'fault' => 'none']), 403, 'forbidden');
    expectApiError(asUser($zoneAdmin, ClientApp::Admin)->postJson('/api/v1/admin/riders', ['user_id' => makeUser()->uuid, 'rider_type' => 'independent']), 403, 'forbidden');
    expectApiError(asUser($this->customer, ClientApp::Customer)->getJson('/api/v1/rider/jobs'), 403, 'forbidden');
    expect($order->fresh()->status)->toBe(OrderStatus::Placed);
});

it('validates rider creation and updates an existing profile', function () {
    $user = makeUser();
    $store = fn (array $body) => asUser($this->admin, ClientApp::Admin)->postJson('/api/v1/admin/riders', $body + ['user_id' => $user->uuid]);

    expectApiError($store(['rider_type' => 'vendor_owned']), 400, 'validation_failed');
    expectApiError($store(['rider_type' => 'independent', 'vendor_id' => $this->vendor->uuid]), 400, 'validation_failed');
    expectApiError(asUser($this->admin, ClientApp::Admin)->postJson('/api/v1/admin/riders', ['user_id' => (string) Str::uuid(), 'rider_type' => 'independent']), 404, 'not_found');

    $store(['rider_type' => 'independent'])->assertCreated()->assertJsonPath('data.rider_type', 'independent');
    $store(['rider_type' => 'wegodeliva_managed', 'vehicle_kind' => 'bicycle'])->assertOk()->assertJsonPath('data.vehicle_kind', 'bicycle');
    expect($user->fresh()->hasRole(RoleName::Rider))->toBeTrue();
});
