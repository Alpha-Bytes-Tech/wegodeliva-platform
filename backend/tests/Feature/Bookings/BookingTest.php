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
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Zone;
use App\Services\Catalogue\ZoneLocator;
use App\Services\Ledger\EarningsLedger;
use App\Services\Ledger\WalletLedger;
use App\Services\Payments\Gateways\PaystackClient;
use Database\Seeders\CitySeeder;
use Database\Seeders\VendorTypeSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Support\Checkout;
use Tests\Support\FakeGateway;

/*
 * Service bookings (spec 04 §4.8, spec 05 §5.9). "Now" is Tuesday 1 Dec 2026, 08:00 in Lagos.
 * The cleaning service costs ₦5,000/hour, with an extra cleaner at ₦1,000/hour and supplies at
 * ₦2,000 once, and a ₦1,000 call-out fee at the customer's address.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-12-01 07:00:00', 'UTC'));
    // The ₦21,000 test booking is above the default ₦20,000 pay-on-delivery limit.
    config(['wegodeliva.payments.pay_on_delivery_max_kobo' => 5_000_000]);
    $this->seed([VendorTypeSeeder::class, CitySeeder::class]);
    $this->customer = makeUser();
    $this->token = Checkout::token($this->customer);
    $this->address = Checkout::address($this->customer);
    $this->owner = makeUser(RoleName::VendorOwner);
    $this->admin = makeUser(RoleName::Admin);
    $this->provider = Vendor::factory()->ofType('services')->withMember($this->owner)->create(['name' => 'Sparkle Cleaners']);
    $this->service = Product::factory()->for($this->provider)->create([
        'name' => 'Deep cleaning', 'price_kobo' => 500000, 'stock_qty' => null,
        'pricing_unit' => 'hour', 'min_units' => 2, 'max_units' => 8, 'location_mode' => 'either',
        'callout_fee_mode' => 'flat', 'callout_fee_kobo' => 100000, 'capacity' => 1,
    ]);
    $group = $this->provider->optionGroups()->create(['name' => 'Extras', 'max_select' => 2]);
    $this->extraCleaner = $group->options()->create(['name' => 'Extra cleaner', 'price_kobo' => 100000, 'per_unit' => true]);
    $this->supplies = $group->options()->create(['name' => 'Supplies', 'price_kobo' => 200000, 'per_unit' => false]);
    $this->service->optionGroups()->attach($group->id);
    $this->start = Carbon::parse('2026-12-02 09:00:00', 'UTC'); // Wednesday 10:00 in Lagos
});

function bookingBody(array $extra = []): array
{
    return array_merge([
        'service_id' => test()->service->uuid,
        'units' => 3,
        'option_ids' => [test()->extraCleaner->uuid, test()->supplies->uuid],
        'starts_at' => test()->start->toIso8601String(),
        'location' => 'at_customer',
        'address_id' => test()->address->uuid,
    ], $extra);
}

function bookAs(User $user, ClientApp $app = ClientApp::Customer)
{
    app('auth')->forgetGuards();

    return test()->withToken(tokenFor($user, $app));
}

function bookingQuote(array $body): TestResponse
{
    return bookAs(test()->customer)->postJson('/api/v1/me/bookings/quote', $body);
}

function bookingOrder(array $extra = [], string $method = 'pay_on_delivery', string $key = 'b1'): Order
{
    $quote = bookingQuote(bookingBody($extra))->assertCreated()->json('data.id');
    app('auth')->forgetGuards();
    $code = Checkout::place(test()->token, $quote, $method, $key)->assertCreated()->json('data.code');

    return Order::query()->where('code', $code)->sole();
}

function providerAction(Order $order, string $action, array $body = []): TestResponse
{
    return bookAs(test()->owner, ClientApp::Vendor)->postJson("/api/v1/vendor/stores/{$order->vendor->uuid}/orders/{$order->code}/{$action}", $body);
}

function walletPaid(int $amount = 15_000_000): void
{
    app(WalletLedger::class)->credit(test()->customer, $amount, WalletKind::TopUp, 'top-'.$amount);
}

it('lets a services store list bookable services; other stores cannot', function () {
    $url = "/api/v1/vendor/stores/{$this->provider->uuid}/products";
    $created = bookAs($this->owner, ClientApp::Vendor)->postJson($url, [
        'name' => 'Haircut', 'price_kobo' => 300000, 'pricing_unit' => 'fixed', 'location_mode' => 'at_provider', 'duration_minutes' => 45, 'capacity' => 2,
    ])->assertCreated()
        ->assertJsonPath('data.service.pricing_unit', 'fixed')
        ->assertJsonPath('data.service.callout_fee_mode', 'none')
        ->assertJsonPath('data.service.capacity', 2)
        ->json('data.id');

    expect(expectApiError(bookAs($this->owner, ClientApp::Vendor)->postJson($url, ['name' => 'X', 'price_kobo' => 1, 'pricing_unit' => 'fixed', 'location_mode' => 'either', 'stock_qty' => 3]), 400, 'validation_failed')['fields'])->toHaveKey('stock_qty');
    expect(expectApiError(bookAs($this->owner, ClientApp::Vendor)->postJson($url, ['name' => 'X', 'price_kobo' => 1]), 400, 'validation_failed')['fields'])->toHaveKeys(['pricing_unit', 'location_mode']);

    $shop = Vendor::factory()->withMember($this->owner)->create();
    $error = expectApiError(bookAs($this->owner, ClientApp::Vendor)->postJson("/api/v1/vendor/stores/{$shop->uuid}/products", ['name' => 'Rice', 'price_kobo' => 1000, 'pricing_unit' => 'fixed']), 400, 'validation_failed');
    expect($error['fields'])->toHaveKey('pricing_unit');

    $this->getJson("/api/v1/catalogue/products/{$created}")->assertOk()->assertJsonPath('data.service.duration_minutes', 45);
    $this->getJson("/api/v1/catalogue/products/{$this->service->uuid}")->assertOk()
        ->assertJsonPath('data.option_groups.0.options.1.per_unit', false);
});

it('offers free start times inside opening hours, without taken slots', function () {
    $this->provider->hours()->delete();
    foreach (range(0, 6) as $day) {
        $this->provider->hours()->create(['weekday' => $day, 'opens_at' => '09:00', 'closes_at' => '17:00']);
    }
    $url = "/api/v1/catalogue/services/{$this->service->uuid}/slots?date=2026-12-02&units=3";

    $starts = $this->getJson($url)->assertOk()->json('data.starts');
    // 09:00 to 14:00 Lagos (a 3-hour job must end by 17:00), every 30 minutes.
    expect($starts)->toHaveCount(11)
        ->and($starts[0])->toBe('2026-12-02T08:00:00+00:00')
        ->and(end($starts))->toBe('2026-12-02T13:00:00+00:00');

    bookingOrder();
    $after = $this->getJson($url)->assertOk()->json('data.starts');
    // 10:00–13:00 is taken: starts from 07:30 to 11:30 Lagos would overlap it.
    expect($after)->toBe(['2026-12-02T12:00:00+00:00', '2026-12-02T12:30:00+00:00', '2026-12-02T13:00:00+00:00']);

    // Today the lead time rules out earlier starts.
    $this->travelTo(Carbon::parse('2026-12-02 10:10:00', 'UTC'));
    expect($this->getJson($url)->assertOk()->json('data.starts'))->toBe(['2026-12-02T12:00:00+00:00', '2026-12-02T12:30:00+00:00', '2026-12-02T13:00:00+00:00']);

    $goods = Checkout::product(Checkout::vendor());
    $this->getJson("/api/v1/catalogue/services/{$goods->uuid}/slots?date=2026-12-02")->assertNotFound();
});

it('offers whole days for day-priced services, only on open days', function () {
    $this->service->update(['pricing_unit' => 'day', 'min_units' => 1]);
    $starts = $this->getJson("/api/v1/catalogue/services/{$this->service->uuid}/slots?date=2026-12-03")->assertOk()->json('data.starts');

    expect($starts)->toHaveCount(48);
});

it('prices a booking: hours × rate, options per hour or once, call-out fee at the customer', function () {
    $quote = bookingQuote(bookingBody())->assertCreated()
        ->assertJsonPath('data.fulfilment', 'delivery')
        ->assertJsonPath('data.booking.units', 3)
        ->assertJsonPath('data.booking.ends_at', '2026-12-02T12:00:00+00:00')
        ->assertJsonPath('data.booking.location', 'at_customer')
        ->assertJsonPath('data.price.subtotal.amount_kobo', 2000000)
        ->assertJsonPath('data.price.delivery_fee.amount_kobo', 100000)
        ->assertJsonPath('data.price.tax.amount_kobo', 0)
        ->assertJsonPath('data.price.total.amount_kobo', 2100000)
        ->json('data');
    expect(array_column($quote['price']['lines'][0]['options'], 'price_kobo'))->toBe([300000, 200000]);

    bookingQuote(bookingBody(['location' => 'at_provider', 'address_id' => null, 'option_ids' => []]))->assertCreated()
        ->assertJsonPath('data.fulfilment', 'pickup')
        ->assertJsonPath('data.price.delivery_fee.amount_kobo', 0)
        ->assertJsonPath('data.price.total.amount_kobo', 1500000);
});

it('refuses bookings that do not fit the service or the provider', function () {
    expectApiError(bookingQuote(bookingBody(['service_id' => Checkout::product(Checkout::vendor())->uuid])), 422, 'product_unavailable');
    expect(expectApiError(bookingQuote(bookingBody(['units' => 1])), 400, 'validation_failed')['fields'])->toHaveKey('units');
    expectApiError(bookingQuote(bookingBody(['units' => 9])), 400, 'validation_failed');

    $this->service->update(['max_units' => null]);
    bookingQuote(bookingBody(['units' => 9]))->assertCreated();
    $this->service->update(['pricing_unit' => 'fixed']);
    expectApiError(bookingQuote(bookingBody(['units' => 2])), 400, 'validation_failed');
    $this->service->update(['pricing_unit' => 'hour', 'location_mode' => 'at_provider']);
    expectApiError(bookingQuote(bookingBody()), 422, 'fulfilment_unavailable');
    $this->service->update(['location_mode' => 'at_customer']);
    expectApiError(bookingQuote(bookingBody(['location' => 'at_provider', 'address_id' => null])), 422, 'fulfilment_unavailable');

    $stranger = Checkout::address(makeUser());
    expectApiError(bookingQuote(bookingBody(['address_id' => $stranger->uuid])), 400, 'validation_failed');
    $far = Checkout::address($this->customer, 6.70, 3.385);
    expectApiError(bookingQuote(bookingBody(['address_id' => $far->uuid])), 422, 'address_not_deliverable');

    expectApiError(bookingQuote(bookingBody(['starts_at' => now()->addMinutes(30)->toIso8601String()])), 422, 'schedule_invalid');
    expectApiError(bookingQuote(bookingBody(['starts_at' => now()->addDays(31)->toIso8601String()])), 422, 'schedule_invalid');
    $this->provider->hours()->delete();
    expectApiError(bookingQuote(bookingBody()), 422, 'schedule_invalid');

    config(['wegodeliva.bookings.enabled' => false]);
    expectApiError(bookingQuote(bookingBody()), 403, 'bookings_disabled');
});

it('accepts an address in one of the provider\'s zones, beyond its radius', function () {
    $zone = Zone::query()->firstOrFail();
    $this->provider->zones()->attach($zone->id);
    app()->instance(ZoneLocator::class, new class($zone) extends ZoneLocator
    {
        public function __construct(private readonly Zone $zone) {}

        public function zoneAt(float $lat, float $lng): ?Zone
        {
            return $this->zone;
        }
    });
    $far = Checkout::address($this->customer, 6.70, 3.385);

    bookingQuote(bookingBody(['address_id' => $far->uuid]))->assertCreated();
});

it('holds a slot up to the service\'s capacity', function () {
    bookingOrder();
    expectApiError(bookingQuote(bookingBody()), 409, 'slot_unavailable');
    // A booking ending as this one starts does not clash.
    bookingQuote(bookingBody(['starts_at' => $this->start->copy()->subHours(3)->toIso8601String()]))->assertCreated();

    $this->service->update(['capacity' => 2]);
    bookingQuote(bookingBody())->assertCreated();
});

it('takes a booking from request to confirmed completion, paying the provider only then', function () {
    $order = bookingOrder(['coupon_code' => null]);
    expect($order->status)->toBe(OrderStatus::Requested)
        ->and($order->type)->toBe('services')
        ->and($order->placed_at)->not->toBeNull()
        ->and($order->units)->toBe(3)
        ->and($order->starts_at->equalTo($this->start))->toBeTrue()
        ->and($order->service_location)->toBe('at_customer')
        ->and($order->items()->sole()->units)->toBe(3);

    bookAs($this->owner, ClientApp::Vendor)->getJson("/api/v1/vendor/stores/{$this->provider->uuid}/orders?tab=requested")->assertOk()
        ->assertJsonPath('data.0.code', $order->code)
        ->assertJsonPath('data.0.address.line1', '1 Test Street')
        ->assertJsonPath('data.0.booking.location', 'at_customer');

    expectApiError(providerAction($order, 'start', ['start_code' => $order->handover_code]), 409, 'order_status_conflict');
    providerAction($order, 'confirm')->assertOk()->assertJsonPath('data.status', 'confirmed');
    expectApiError(providerAction($order, 'confirm'), 409, 'order_status_conflict');

    $error = expectApiError(providerAction($order, 'start', ['start_code' => $order->handover_code]), 409, 'booking_not_started');
    expect($error['meta']['can_start_at'])->toBe('2026-12-02T08:00:00+00:00');

    $this->travelTo($this->start->copy()->subMinutes(10));
    $wrong = $order->handover_code === '0000' ? '1111' : '0000';
    expect(expectApiError(providerAction($order, 'start', ['start_code' => $wrong]), 422, 'handover_code_invalid')['meta']['attempts_left'])->toBe(2);
    providerAction($order, 'start', ['start_code' => $order->handover_code])->assertOk()->assertJsonPath('data.status', 'in_progress');

    expectApiError(providerAction($order, 'complete'), 400, 'validation_failed');
    $this->travelTo($this->start->copy()->addHours(3));
    providerAction($order, 'complete', ['collected_via' => 'cash'])->assertOk()->assertJsonPath('data.status', 'completed');
    expect(CommissionRecord::query()->where('order_id', $order->id)->exists())->toBeFalse();

    bookAs($this->customer)->getJson("/api/v1/me/orders/{$order->code}")->assertOk()
        ->assertJsonPath('data.booking.can_confirm', true)
        ->assertJsonPath('data.booking.auto_confirms_at', '2026-12-03T12:00:00+00:00')
        ->assertJsonPath('data.handover_code', null);

    bookAs($this->customer)->postJson("/api/v1/me/orders/{$order->code}/confirm-completion")->assertOk()
        ->assertJsonPath('data.booking.can_confirm', false)
        ->assertJsonPath('data.payment_status', 'paid');
    expectApiError(bookAs($this->customer)->postJson("/api/v1/me/orders/{$order->code}/confirm-completion"), 409, 'order_status_conflict');

    $record = CommissionRecord::query()->where('order_id', $order->id)->sole();
    expect($record->vendor_gross_kobo)->toBe(2000000)
        ->and($record->vendor_commission_kobo)->toBe(100000)
        ->and($record->delivery_gross_kobo)->toBe(100000)
        ->and($record->delivery_to_vendor)->toBeTrue();
    $account = app(EarningsLedger::class)->account(EarningsOwner::Vendor, $this->provider->id);
    expect($account->cash_owed_kobo)->toBe(2100000);
});

it('locks the start after three wrong codes', function () {
    $order = bookingOrder();
    providerAction($order, 'confirm')->assertOk();
    $this->travelTo($this->start);
    $wrong = $order->handover_code === '0000' ? '1111' : '0000';

    expectApiError(providerAction($order, 'start', ['start_code' => $wrong]), 422, 'handover_code_invalid');
    expectApiError(providerAction($order, 'start', ['start_code' => $wrong]), 422, 'handover_code_invalid');
    expectApiError(providerAction($order, 'start', ['start_code' => $wrong]), 423, 'handover_locked');
    expectApiError(providerAction($order, 'start', ['start_code' => $order->handover_code]), 423, 'handover_locked');
    expectApiError(providerAction(Order::query()->find($order->id), 'complete', ['collected_via' => 'cash']), 409, 'order_status_conflict');
});

it('refuses booking actions on ordinary orders', function () {
    $shop = Checkout::vendor();
    $shop->members()->attach($this->owner->id, ['role' => 'owner']);
    $quote = Checkout::quote($this->token, Checkout::cart($shop, [[Checkout::product($shop)]], $this->address))->json('data.id');
    app('auth')->forgetGuards();
    $order = Order::query()->where('code', Checkout::place($this->token, $quote, key: 'goods')->json('data.code'))->sole();

    expectApiError(providerAction($order, 'confirm'), 409, 'order_status_conflict');
    expectApiError(bookAs($this->customer)->postJson("/api/v1/me/orders/{$order->code}/confirm-completion"), 409, 'order_status_conflict');
    bookAs($this->customer)->postJson("/api/v1/me/orders/{$order->code}/report-problem", ['reason' => 'Never came'])->assertStatus(409);
});

it('auto-confirms after the window, and lets the customer report a problem before it', function () {
    walletPaid();
    $order = bookingOrder(method: 'wallet');
    $second = bookingOrder(['starts_at' => $this->start->copy()->addDays(2)->toIso8601String()], 'wallet', 'b2');
    providerAction($order, 'confirm')->assertOk();
    providerAction($second, 'confirm')->assertOk();
    $this->travelTo($this->start);
    providerAction($order, 'start', ['start_code' => $order->handover_code])->assertOk();
    providerAction($order, 'complete')->assertOk();

    $this->travelTo($this->start->copy()->addHours(23));
    $this->artisan('bookings:monitor')->assertSuccessful();
    expect($order->fresh()->completion_confirmed_at)->toBeNull();

    bookAs($this->customer)->postJson("/api/v1/me/orders/{$order->code}/report-problem", ['reason' => 'The kitchen was not cleaned'])->assertOk()
        ->assertJsonPath('data.status', 'disputed')
        ->assertJsonPath('data.booking.dispute_reason', 'The kitchen was not cleaned');

    $this->travelTo($this->start->copy()->addHours(30));
    $this->artisan('bookings:monitor')->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::Disputed);

    $this->travelTo($this->start->copy()->addDays(2));
    providerAction($second, 'start', ['start_code' => $second->handover_code])->assertOk();
    providerAction($second, 'complete')->assertOk();
    $this->travelTo($this->start->copy()->addDays(3)->addMinute());
    expectApiError(bookAs($this->customer)->postJson("/api/v1/me/orders/{$second->code}/report-problem", ['reason' => 'Too late now']), 409, 'order_status_conflict');
    $this->artisan('bookings:monitor')->expectsOutputToContain('auto-confirmed 1')->assertSuccessful();
    expect($second->fresh()->completion_confirmed_at)->not->toBeNull()
        ->and(CommissionRecord::query()->where('order_id', $second->id)->exists())->toBeTrue();
});

it('lets support settle a dispute by releasing the money or refunding', function () {
    walletPaid();
    $disputed = function (string $key, Carbon $start): Order {
        $order = bookingOrder(['starts_at' => $start->toIso8601String()], 'wallet', $key);
        providerAction($order, 'confirm')->assertOk();
        $this->travelTo($start);
        providerAction($order, 'start', ['start_code' => $order->handover_code])->assertOk();
        providerAction($order, 'complete')->assertOk();
        bookAs($this->customer)->postJson("/api/v1/me/orders/{$order->code}/report-problem", ['reason' => 'Left early'])->assertOk();

        return $order;
    };
    $released = $disputed('d1', $this->start);
    $refunded = $disputed('d2', $this->start->copy()->addDay());
    $admin = fn (Order $o, array $body) => bookAs($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$o->code}/resolve-dispute", $body);

    $admin($released, ['outcome' => 'release', 'note' => 'Photos show the job was done'])->assertOk()->assertJsonPath('data.status', 'completed');
    expect($released->fresh()->completion_confirmed_at)->not->toBeNull()
        ->and(CommissionRecord::query()->where('order_id', $released->id)->exists())->toBeTrue();
    expectApiError($admin($released, ['outcome' => 'release', 'note' => 'Again']), 409, 'order_status_conflict');

    $balance = app(WalletLedger::class)->wallet($this->customer)->balance_kobo;
    $admin($refunded, ['outcome' => 'refund', 'note' => 'Provider admitted leaving early'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(app(WalletLedger::class)->wallet($this->customer)->fresh()->balance_kobo)->toBe($balance + 2100000)
        ->and(CommissionRecord::query()->where('order_id', $refunded->id)->exists())->toBeFalse()
        ->and(AdminAuditLog::query()->where('action', 'booking.dispute_resolved')->count())->toBe(2);
});

it('cancels free until 12 hours before the start, then keeps half the service price', function () {
    walletPaid();
    $requested = bookingOrder(method: 'wallet', key: 'c1');
    bookAs($this->customer)->getJson("/api/v1/me/orders/{$requested->code}")->assertJsonPath('data.booking.cancellation_fee.amount_kobo', 0);
    bookAs($this->customer)->postJson("/api/v1/me/orders/{$requested->code}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    $late = bookingOrder(method: 'wallet', key: 'c2');
    providerAction($late, 'confirm')->assertOk();
    bookAs($this->customer)->getJson("/api/v1/me/orders/{$late->code}")->assertJsonPath('data.booking.cancellation_fee.amount_kobo', 0);

    $this->travelTo($this->start->copy()->subHours(11));
    bookAs($this->customer)->getJson("/api/v1/me/orders/{$late->code}")->assertJsonPath('data.booking.cancellation_fee.amount_kobo', 1000000);
    $before = app(WalletLedger::class)->wallet($this->customer)->balance_kobo;
    bookAs($this->customer)->postJson("/api/v1/me/orders/{$late->code}/cancel", ['reason' => 'Plans changed'])->assertOk();

    expect(app(WalletLedger::class)->wallet($this->customer)->fresh()->balance_kobo)->toBe($before + 2100000 - 1000000);
    $vendor = app(EarningsLedger::class)->account(EarningsOwner::Vendor, $this->provider->id);
    expect($vendor->entries()->where('reference', "order:{$late->uuid}:cancellation-fee")->exists())->toBeTrue();
});

it('times out unanswered requests, faster for short-notice bookings, and sends reminders', function () {
    walletPaid();
    $soon = bookingOrder(['starts_at' => now()->addHours(2)->toIso8601String()], 'wallet', 't1');
    $later = bookingOrder(['starts_at' => $this->start->copy()->addHours(4)->toIso8601String()], 'wallet', 't2');
    $confirmed = bookingOrder(['starts_at' => $this->start->copy()->addDay()->toIso8601String()], 'wallet', 't3');
    providerAction($confirmed, 'confirm')->assertOk();

    $this->travelTo(now()->addMinutes(31));
    $this->artisan('bookings:monitor')->assertSuccessful();
    expect($soon->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($soon->fresh()->fault?->value)->toBe('vendor')
        ->and($soon->fresh()->payment_status)->toBe(PaymentStatus::Refunded)
        ->and($later->fresh()->status)->toBe(OrderStatus::Requested);

    $this->travelTo(now()->addMinutes(90));
    $this->artisan('bookings:monitor')->assertSuccessful();
    expect($later->fresh()->status)->toBe(OrderStatus::Cancelled);

    // The confirmed booking is on Thursday 10:00 Lagos: a day's reminder, then an hour's.
    $this->travelTo($this->start->copy()->addDay()->subHours(23));
    $this->artisan('bookings:monitor')->expectsOutputToContain('reminders 1')->assertSuccessful();
    $this->artisan('bookings:monitor')->expectsOutputToContain('reminders 0')->assertSuccessful();
    expect($confirmed->fresh()->reminded_day_at)->not->toBeNull()->and($confirmed->fresh()->reminded_hour_at)->toBeNull();
    $this->travelTo($this->start->copy()->addDay()->subMinutes(50));
    $this->artisan('bookings:monitor')->expectsOutputToContain('reminders 1')->assertSuccessful();

    $notified = $this->customer->notifications()->get()->pluck('data.title')->all();
    expect($notified)->toContain('Booking coming up', 'Request sent', 'Booking confirmed');
});

it('lets the provider decline a request with a full refund', function () {
    walletPaid();
    $order = bookingOrder(method: 'wallet');

    providerAction($order, 'reject', ['reason' => 'Fully booked that day'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Refunded);
});

it('sends a paid gateway booking to the provider as a request', function () {
    config(['wegodeliva.payments.paystack' => true]);
    $gateway = new FakeGateway;
    app()->instance(PaystackClient::class, $gateway);
    $order = bookingOrder(method: 'paystack');
    expect($order->status)->toBe(OrderStatus::AwaitingPayment);

    $reference = bookAs($this->customer)->postJson("/api/v1/me/orders/{$order->code}/pay")->assertCreated()->json('data.reference');
    $payment = Payment::query()->where('reference', $reference)->sole();
    $gateway->pays($payment);
    bookAs($this->customer)->postJson("/api/v1/me/payments/{$reference}/verify")->assertOk()->assertJsonPath('data.order.status', 'requested');

    expect($order->fresh()->placed_at)->not->toBeNull();
    // The unpaid booking held the slot meanwhile; it still does.
    expectApiError(bookingQuote(bookingBody()), 409, 'slot_unavailable');
});
