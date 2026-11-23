<?php

use App\Enums\ClientApp;
use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Enums\VendorMemberRole;
use App\Models\AdminAuditLog;
use App\Models\CommissionRecord;
use App\Models\Order;
use App\Models\OrderStop;
use App\Models\PackageType;
use App\Models\ParcelRate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Zone;
use App\Services\Catalogue\ZoneLocator;
use Database\Seeders\CitySeeder;
use Database\Seeders\PackageTypeSeeder;
use Database\Seeders\VendorTypeSeeder;
use Illuminate\Testing\TestResponse;
use Tests\Support\Checkout;

/*
 * Parcels (spec 05 §5.3, spec 09 §9.6). The operator sits at the factory location (6.515, 3.385);
 * stops 1,112 m apart give a 1,446 m road route. Rate: ₦1,000 base + ₦200/km + ₦50 flat size,
 * ₦300 per extra stop — so a two-stop parcel costs ₦1,339.20.
 */
beforeEach(function () {
    $this->seed([VendorTypeSeeder::class, CitySeeder::class, PackageTypeSeeder::class]);
    $this->customer = makeUser();
    $this->token = Checkout::token($this->customer);
    $this->admin = makeUser(RoleName::Admin);
    $this->owner = makeUser(RoleName::VendorOwner);
    $this->operator = Vendor::factory()->ofType('parcels')->withMember($this->owner)->create(['name' => 'Swift Parcels', 'allows_scheduled_orders' => true]);
    $this->document = PackageType::query()->where('name', 'Document')->sole();
    $this->box = PackageType::query()->where('name', 'Small parcel')->sole();
    $this->rate = parcelRateFor($this->operator, $this->document);
    parcelRateFor($this->operator, $this->box);
});

function parcelRateFor(Vendor $operator, PackageType $type, array $attributes = []): ParcelRate
{
    $rate = new ParcelRate(array_merge([
        'base_price_kobo' => 100000, 'distance_rate_kobo' => 20000, 'distance_per_km' => true,
        'size_rate_kobo' => 5000, 'size_per_kg' => false, 'extra_stop_fee_kobo' => 30000,
    ], $attributes));
    $rate->forceFill(['vendor_id' => $operator->id, 'package_type_id' => $type->id])->save();

    return $rate;
}

function parcelStop(float $lat, string $name = 'Ada'): array
{
    return ['line1' => '2 Herbert Macaulay Way', 'landmark' => 'By the bus stop', 'lat' => $lat, 'lng' => 3.385, 'contact_name' => $name, 'contact_phone' => '08031234567'];
}

function parcelBody(array $extra = [], ?PackageType $type = null, array $lats = [6.520, 6.530]): array
{
    return array_merge([
        'package_type_id' => ($type ?? test()->document)->uuid,
        'stops' => array_map(fn (float $lat) => parcelStop($lat), $lats),
        'payer' => 'sender',
    ], $extra);
}

function parcelAs(User $user, ClientApp $app = ClientApp::Customer)
{
    app('auth')->forgetGuards();

    return test()->withToken(tokenFor($user, $app));
}

function parcelQuote(array $body): TestResponse
{
    return parcelAs(test()->customer)->postJson('/api/v1/me/parcels/quote', $body + ['operator_id' => test()->operator->uuid]);
}

/** Quotes and places a parcel paid on delivery; returns the order. */
function parcelOrder(array $body = [], array $lats = [6.520, 6.530], ?PackageType $type = null, string $key = 'tap-1'): Order
{
    $quote = parcelQuote(parcelBody($body, $type, $lats))->assertCreated()->json('data.id');
    app('auth')->forgetGuards();
    $code = Checkout::place(test()->token, $quote, 'pay_on_delivery', $key)->assertCreated()->json('data.code');

    return Order::query()->where('code', $code)->sole();
}

function parcelRider(Vendor $ownedBy): User
{
    $rider = makeUser();
    parcelAs(test()->admin, ClientApp::Admin)->postJson('/api/v1/admin/riders', [
        'user_id' => $rider->uuid, 'rider_type' => 'vendor_owned', 'vendor_id' => $ownedBy->uuid,
    ])->assertCreated();

    return $rider->fresh();
}

function onTheRoad(Order $order): User
{
    $rider = parcelRider($order->vendor);
    $vendorUrl = "/api/v1/vendor/stores/{$order->vendor->uuid}/orders/{$order->code}";
    parcelAs(test()->owner, ClientApp::Vendor)->postJson("{$vendorUrl}/accept", ['prep_time_minutes' => 10])->assertOk();
    parcelAs(test()->owner, ClientApp::Vendor)->postJson("{$vendorUrl}/assign-rider", ['rider_id' => $rider->uuid])->assertOk()
        ->assertJsonPath('data.rider.id', $rider->uuid);
    parcelAs(test()->owner, ClientApp::Vendor)->postJson("{$vendorUrl}/ready")->assertOk();
    parcelAs($rider, ClientApp::Rider)->postJson("/api/v1/rider/jobs/{$order->code}/picked-up")->assertOk();

    return $rider;
}

it('lists active package types publicly', function () {
    $this->box->update(['is_active' => false]);

    $names = $this->getJson('/api/v1/catalogue/package-types')->assertOk()->json('data.*.name');

    expect($names)->toBe(['Document', 'Medium box', 'Large box']);
});

it('lists the operators that cover every stop, cheapest first, with why others cannot', function () {
    $cheap = Vendor::factory()->ofType('parcels')->create(['name' => 'Budget Couriers']);
    parcelRateFor($cheap, $this->document, ['base_price_kobo' => 50000]);
    $heavy = Vendor::factory()->ofType('parcels')->create(['name' => 'Heavy Movers']);
    parcelRateFor($heavy, $this->document, ['size_per_kg' => true]);
    $shut = Vendor::factory()->ofType('parcels')->create(['name' => 'Night Riders']);
    $shut->forceFill(['manual_state' => 'closed'])->save();
    parcelRateFor($shut, $this->document);
    $far = Vendor::factory()->ofType('parcels')->at(6.60, 3.50)->create(['name' => 'Far Away']);
    parcelRateFor($far, $this->document);
    $off = Vendor::factory()->ofType('parcels')->create(['name' => 'Switched Off']);
    parcelRateFor($off, $this->document, ['is_active' => false]);
    $food = Vendor::factory()->create(['name' => 'Not A Courier']);
    parcelRateFor($food, $this->document);

    $options = parcelAs($this->customer)->postJson('/api/v1/me/parcels/options', parcelBody())->assertOk()->json('data');

    expect(array_column(array_column($options, 'operator'), 'name'))->toBe(['Budget Couriers', 'Swift Parcels', 'Heavy Movers', 'Night Riders'])
        ->and($options[0]['price']['amount_kobo'])->toBe(83920)
        ->and($options[1]['price']['amount_kobo'])->toBe(133920)
        ->and($options[1]['route_m'])->toBe(1446)
        ->and($options[2])->toMatchArray(['available' => false, 'reason' => 'weight_required', 'price' => null])
        ->and($options[3]['reason'])->toBe('closed');
});

it('counts a stop inside one of the operator\'s zones as covered, even beyond its radius', function () {
    $zone = Zone::query()->firstOrFail();
    $this->operator->zones()->attach($zone->id);
    app()->instance(ZoneLocator::class, new class($zone) extends ZoneLocator
    {
        public function __construct(private readonly Zone $zone) {}

        public function zoneAt(float $lat, float $lng): ?Zone
        {
            return $lat > 6.6 ? $this->zone : null;
        }
    });

    $options = parcelAs($this->customer)->postJson('/api/v1/me/parcels/options', parcelBody(lats: [6.520, 6.700]))->assertOk()->json('data');
    expect($options)->toHaveCount(1);

    $other = Zone::query()->whereKeyNot($zone->id)->firstOrFail();
    $this->operator->zones()->sync([$other->id]);
    parcelAs($this->customer)->postJson('/api/v1/me/parcels/options', parcelBody(lats: [6.520, 6.700]))->assertOk()->assertJsonCount(0, 'data');
});

it('prices a parcel with the chosen operator: no VAT, no delivery fee, payment options', function () {
    $quote = parcelQuote(parcelBody(['weight_g' => 800]))->assertCreated()
        ->assertJsonPath('data.vendor.name', 'Swift Parcels')
        ->assertJsonPath('data.fulfilment', 'delivery')
        ->assertJsonPath('data.delivery', null)
        ->assertJsonPath('data.price.subtotal.amount_kobo', 133920)
        ->assertJsonPath('data.price.tax.amount_kobo', 0)
        ->assertJsonPath('data.price.delivery_fee.amount_kobo', 0)
        ->assertJsonPath('data.price.total.amount_kobo', 133920)
        ->assertJsonPath('data.parcel.package_type.name', 'Document')
        ->assertJsonPath('data.parcel.weight_g', 800)
        ->assertJsonPath('data.parcel.dimensions_cm', null)
        ->assertJsonPath('data.parcel.stops.0.kind', 'pickup')
        ->assertJsonPath('data.parcel.stops.1.kind', 'dropoff')
        ->assertJsonPath('data.parcel.stops.1.contact_phone', '+2348031234567')
        ->assertJsonPath('data.parcel.pricing.route_m', 1446)
        ->json('data');

    expect(collect($quote['payment_options'])->pluck('available', 'method')->all())
        ->toBe(['paystack' => false, 'flutterwave' => false, 'wallet' => false, 'pay_on_delivery' => true]);
});

it('lets a paying recipient settle only on delivery', function () {
    config(['wegodeliva.payments.paystack' => true]);

    $options = parcelQuote(parcelBody(['payer' => 'recipient']))->assertCreated()->json('data.payment_options');

    expect(collect($options)->pluck('reason', 'method')->all())
        ->toBe(['paystack' => 'recipient_pays', 'flutterwave' => 'disabled', 'wallet' => 'recipient_pays', 'pay_on_delivery' => null]);
});

it('refuses parcels it cannot price', function () {
    expectApiError(parcelQuote(parcelBody(['package_type_id' => (string) Str::uuid()])), 422, 'package_type_unavailable');

    $large = PackageType::query()->where('name', 'Large box')->sole();
    expectApiError(parcelQuote(parcelBody(type: $large)), 422, 'vendor_unavailable');

    $error = expectApiError(parcelQuote(parcelBody(lats: [6.520, 6.700])), 422, 'address_not_deliverable');
    expect($error['fields'])->toHaveKey('stops.1');

    $this->rate->update(['size_per_kg' => true]);
    expect(expectApiError(parcelQuote(parcelBody()), 400, 'validation_failed')['fields'])->toHaveKey('weight_g');

    $this->rate->update(['size_per_kg' => false, 'requires_dimensions' => true]);
    expect(expectApiError(parcelQuote(parcelBody(['weight_g' => 500, 'length_cm' => 30])), 400, 'validation_failed')['fields'])->toHaveKey('dimensions');
    parcelQuote(parcelBody(['weight_g' => 500, 'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 10]))->assertCreated()
        ->assertJsonPath('data.parcel.dimensions_cm', ['length' => 30, 'width' => 20, 'height' => 10]);
});

it('checks the operator is open now, or can collect at the scheduled time', function () {
    $this->travelTo(now()->setTimezone('Africa/Lagos')->setTime(9, 0)->utc());

    $this->operator->forceFill(['manual_state' => 'busy'])->save();
    expectApiError(parcelQuote(parcelBody()), 422, 'vendor_closed');
    $this->operator->forceFill(['manual_state' => null])->save();

    $soon = now()->addMinutes(30)->toIso8601String();
    expectApiError(parcelQuote(parcelBody(['scheduled_for' => $soon])), 422, 'schedule_invalid');

    $this->rate->update(['max_advance_booking_days' => 1]);
    expectApiError(parcelQuote(parcelBody(['scheduled_for' => now()->addDays(2)->toIso8601String()])), 422, 'schedule_invalid');

    $tomorrow = now()->addDay()->toIso8601String();
    parcelQuote(parcelBody(['scheduled_for' => $tomorrow]))->assertCreated()->assertJsonPath('data.scheduled_for', now()->addDay()->toIso8601String());

    $this->operator->hours()->delete();
    expectApiError(parcelQuote(parcelBody(['scheduled_for' => $tomorrow])), 422, 'schedule_invalid');

    $this->operator->update(['allows_scheduled_orders' => false]);
    expectApiError(parcelQuote(parcelBody(['scheduled_for' => $tomorrow])), 422, 'schedule_invalid');
});

it('validates stops, and honours the parcel feature flags', function () {
    $tooMany = parcelBody(lats: [6.52, 6.53, 6.52, 6.53, 6.52, 6.53]);
    expect(expectApiError(parcelQuote($tooMany), 400, 'validation_failed')['fields'])->toHaveKey('stops');
    expect(expectApiError(parcelAs($this->customer)->postJson('/api/v1/me/parcels/options', parcelBody(['operator_id' => $this->operator->uuid])), 400, 'validation_failed')['fields'])->toHaveKey('operator_id');

    config(['wegodeliva.parcels.multi_stop' => false]);
    expectApiError(parcelQuote(parcelBody(lats: [6.52, 6.53, 6.54])), 400, 'validation_failed');

    config(['wegodeliva.parcels.enabled' => false]);
    expectApiError(parcelAs($this->customer)->postJson('/api/v1/me/parcels/options', parcelBody()), 403, 'parcels_disabled');
    expectApiError(parcelQuote(parcelBody()), 403, 'parcels_disabled');
});

it('places a parcel order with its stops and a code for every drop-off when the type needs it', function () {
    $order = parcelOrder(['weight_g' => 1200, 'payer' => 'recipient'], [6.520, 6.530, 6.540]);

    expect($order->type)->toBe('parcels')
        ->and($order->status)->toBe(OrderStatus::Placed)
        ->and($order->package_type_id)->toBe($this->document->id)
        ->and($order->weight_g)->toBe(1200)
        ->and($order->payer)->toBe('recipient')
        ->and($order->distance_m)->toBe(2891)
        ->and($order->subtotal_kobo)->toBe(192820)
        ->and($order->tax_kobo)->toBe(0)
        ->and($order->total_kobo)->toBe(192820);

    $stops = OrderStop::query()->where('order_id', $order->id)->orderBy('sequence')->get();
    expect($stops->pluck('kind')->all())->toBe(['pickup', 'dropoff', 'dropoff'])
        ->and($stops[0]->verification_code)->toBeNull()
        ->and($stops[1]->verification_code)->toMatch('/^\d{4}$/')
        ->and($stops[2]->verification_code)->toBe($order->handover_code);

    $shown = parcelAs($this->customer)->getJson("/api/v1/me/orders/{$order->code}")->assertOk()
        ->assertJsonPath('data.type', 'parcels')
        ->assertJsonPath('data.parcel.payer', 'recipient')
        ->assertJsonPath('data.parcel.stops.1.needs_code', true)
        ->json('data.parcel.stops');
    expect(array_column($shown, 'code'))->toBe([null, $stops[1]->verification_code, $order->handover_code]);
});

it('gives intermediate drop-offs no code when the package type does not need one', function () {
    $order = parcelOrder(lats: [6.520, 6.530, 6.540], type: $this->box);

    $codes = OrderStop::query()->where('order_id', $order->id)->orderBy('sequence')->get()->pluck('verification_code')->all();
    expect($codes)->toBe([null, null, $order->handover_code]);
});

it('delivers a multi-stop parcel stop by stop, then settles the whole price to the operator', function () {
    $order = parcelOrder(lats: [6.520, 6.530, 6.540]);
    $rider = onTheRoad($order);
    $middle = OrderStop::query()->where('order_id', $order->id)->where('sequence', 2)->sole();
    $stopUrl = fn (int $seq) => "/api/v1/rider/jobs/{$order->code}/stops/{$seq}/complete";

    expect(OrderStop::query()->where('order_id', $order->id)->where('sequence', 1)->value('verified_at'))->not->toBeNull();
    parcelAs($rider, ClientApp::Rider)->getJson("/api/v1/rider/jobs/{$order->code}")->assertOk()
        ->assertJsonPath('data.parcel.stops.1.contact_name', 'Ada')
        ->assertJsonPath('data.parcel.stops.1.code', null);

    expectApiError(parcelAs($rider, ClientApp::Rider)->postJson("/api/v1/rider/jobs/{$order->code}/deliver", ['handover_code' => $order->handover_code, 'collected_via' => 'cash']), 409, 'stops_incomplete');
    expectApiError(parcelAs($rider, ClientApp::Rider)->postJson($stopUrl(1)), 409, 'stop_not_completable');
    expectApiError(parcelAs($rider, ClientApp::Rider)->postJson($stopUrl(3), ['code' => $order->handover_code]), 409, 'stop_not_completable');
    expectApiError(parcelAs($rider, ClientApp::Rider)->postJson($stopUrl(9)), 409, 'stop_not_completable');

    $wrong = $middle->verification_code === '0000' ? '1111' : '0000';
    expect(expectApiError(parcelAs($rider, ClientApp::Rider)->postJson($stopUrl(2), ['code' => $wrong]), 422, 'handover_code_invalid')['meta']['attempts_left'])->toBe(2);
    expectApiError(parcelAs($rider, ClientApp::Rider)->postJson($stopUrl(2)), 422, 'handover_code_invalid');
    parcelAs($rider, ClientApp::Rider)->postJson($stopUrl(2), ['code' => $middle->verification_code])->assertOk()
        ->assertJsonPath('data.parcel.stops.1.done_at', fn ($at) => $at !== null);
    expectApiError(parcelAs($rider, ClientApp::Rider)->postJson($stopUrl(2), ['code' => $middle->verification_code]), 409, 'stop_not_completable');

    parcelAs($rider, ClientApp::Rider)->postJson("/api/v1/rider/jobs/{$order->code}/deliver", ['handover_code' => $order->handover_code, 'collected_via' => 'cash'])->assertOk()
        ->assertJsonPath('data.status', 'delivered');

    expect(OrderStop::query()->where('order_id', $order->id)->whereNull('verified_at')->count())->toBe(0);
    $record = CommissionRecord::query()->where('order_id', $order->id)->sole();
    expect($record->vendor_gross_kobo)->toBe(192820)
        ->and($record->vendor_commission_kobo)->toBe(9641)
        ->and($record->delivery_gross_kobo)->toBe(0)
        ->and($record->delivery_to_vendor)->toBeTrue()
        ->and($record->vat_collected_kobo)->toBe(0);

    $codes = parcelAs($this->customer)->getJson("/api/v1/me/orders/{$order->code}")->assertOk()->json('data.parcel.stops.*.code');
    expect($codes)->toBe([null, null, null]);
});

it('locks a stop after three wrong codes, and completes code-free stops without one', function () {
    $order = parcelOrder(lats: [6.520, 6.530, 6.540]);
    $rider = onTheRoad($order);
    $middle = OrderStop::query()->where('order_id', $order->id)->where('sequence', 2)->sole();
    $wrong = $middle->verification_code === '0000' ? '1111' : '0000';
    $url = "/api/v1/rider/jobs/{$order->code}/stops/2/complete";

    expectApiError(parcelAs($rider, ClientApp::Rider)->postJson($url, ['code' => $wrong]), 422, 'handover_code_invalid');
    expectApiError(parcelAs($rider, ClientApp::Rider)->postJson($url, ['code' => $wrong]), 422, 'handover_code_invalid');
    expectApiError(parcelAs($rider, ClientApp::Rider)->postJson($url, ['code' => $wrong]), 423, 'handover_locked');
    expectApiError(parcelAs($rider, ClientApp::Rider)->postJson($url, ['code' => $middle->verification_code]), 423, 'handover_locked');

    $boxOrder = parcelOrder(lats: [6.520, 6.530, 6.540], type: $this->box, key: 'tap-2');
    $boxRider = onTheRoad($boxOrder);
    parcelAs($boxRider, ClientApp::Rider)->postJson("/api/v1/rider/jobs/{$boxOrder->code}/stops/2/complete")->assertOk();
});

it('only completes stops of a parcel that is on the road', function () {
    $order = parcelOrder(lats: [6.520, 6.530, 6.540]);
    $rider = parcelRider($this->operator);
    parcelAs($this->owner, ClientApp::Vendor)->postJson("/api/v1/vendor/stores/{$this->operator->uuid}/orders/{$order->code}/assign-rider", ['rider_id' => $rider->uuid])->assertOk();

    expectApiError(parcelAs($rider, ClientApp::Rider)->postJson("/api/v1/rider/jobs/{$order->code}/stops/2/complete"), 409, 'order_status_conflict');
});

it('gives parcel jobs only to the operator\'s own riders', function () {
    $order = parcelOrder();
    $freelancer = makeUser();
    parcelAs($this->admin, ClientApp::Admin)->postJson('/api/v1/admin/riders', ['user_id' => $freelancer->uuid, 'rider_type' => 'independent'])->assertCreated();
    $otherOperator = Vendor::factory()->ofType('parcels')->create();
    $stranger = parcelRider($otherOperator);

    expectApiError(parcelAs($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$order->code}/assign-rider", ['rider_id' => $freelancer->uuid]), 422, 'rider_not_eligible');
    expectApiError(parcelAs($this->owner, ClientApp::Vendor)->postJson("/api/v1/vendor/stores/{$this->operator->uuid}/orders/{$order->code}/assign-rider", ['rider_id' => $stranger->uuid]), 422, 'rider_not_eligible');

    $own = parcelRider($this->operator);
    parcelAs($this->owner, ClientApp::Vendor)->getJson("/api/v1/vendor/stores/{$this->operator->uuid}/riders")->assertOk()
        ->assertJsonPath('data.0.id', $own->uuid)
        ->assertJsonPath('data.0.active_jobs', 0)
        ->assertJsonCount(1, 'data');
    parcelAs($this->admin, ClientApp::Admin)->postJson("/api/v1/admin/orders/{$order->code}/assign-rider", ['rider_id' => $own->uuid])->assertOk();
    expect($order->fresh()->rider_id)->toBe($own->id);
});

it('lets a store assign its own riders to ordinary orders too', function () {
    $store = Checkout::vendor();
    $store->members()->attach($this->owner->id, ['role' => VendorMemberRole::Staff->value]);
    $product = Checkout::product($store);
    $quote = Checkout::quote($this->token, Checkout::cart($store, [[$product]], Checkout::address($this->customer)))->assertCreated()->json('data.id');
    app('auth')->forgetGuards();
    $code = Checkout::place($this->token, $quote)->assertCreated()->json('data.code');
    $rider = parcelRider($store);

    parcelAs($this->owner, ClientApp::Vendor)->postJson("/api/v1/vendor/stores/{$store->uuid}/orders/{$code}/assign-rider", ['rider_id' => $rider->uuid])->assertOk()
        ->assertJsonPath('data.rider.id', $rider->uuid)
        ->assertJsonMissingPath('data.parcel');
    $history = Order::query()->where('code', $code)->sole()->history()->get();
    expect($history->last()->actor_role->value)->toBe('vendor')->and($history->last()->reason)->toBe("Assigned rider {$rider->name}");
});

it('lets the operator owner set rates per package type; staff can only look', function () {
    $staff = makeUser(RoleName::VendorStaff);
    $this->operator->members()->attach($staff->id, ['role' => 'staff']);
    $large = PackageType::query()->where('name', 'Large box')->sole();
    $url = "/api/v1/vendor/stores/{$this->operator->uuid}/parcel-rates";
    $body = ['base_price_kobo' => 250000, 'distance_rate_kobo' => 30000, 'distance_per_km' => true, 'size_rate_kobo' => 10000, 'size_per_kg' => true, 'extra_stop_fee_kobo' => 50000, 'requires_dimensions' => true];

    parcelAs($this->owner, ClientApp::Vendor)->putJson("{$url}/{$large->uuid}", $body)->assertCreated()
        ->assertJsonPath('data.package_type.name', 'Large box')
        ->assertJsonPath('data.base_price.amount_kobo', 250000)
        ->assertJsonPath('data.requires_dimensions', true);
    parcelAs($this->owner, ClientApp::Vendor)->putJson("{$url}/{$large->uuid}", ['is_active' => false] + $body)->assertOk()
        ->assertJsonPath('data.is_active', false);
    expect(ParcelRate::query()->where('vendor_id', $this->operator->id)->count())->toBe(3);

    parcelAs($staff, ClientApp::Vendor)->getJson($url)->assertOk()->assertJsonCount(3, 'data');
    parcelAs($staff, ClientApp::Vendor)->putJson("{$url}/{$large->uuid}", $body)->assertForbidden();

    $food = Vendor::factory()->withMember($this->owner)->create();
    expectApiError(parcelAs($this->owner, ClientApp::Vendor)->getJson("/api/v1/vendor/stores/{$food->uuid}/parcel-rates"), 409, 'not_parcel_operator');
});

it('lets admins manage package types, audited', function () {
    $created = parcelAs($this->admin, ClientApp::Admin)->postJson('/api/v1/admin/package-types', ['name' => 'Cake box', 'requires_stop_verification' => true, 'sort_order' => 9])
        ->assertCreated()->assertJsonPath('data.requires_stop_verification', true)->json('data.id');
    parcelAs($this->admin, ClientApp::Admin)->patchJson("/api/v1/admin/package-types/{$created}", ['is_active' => false])->assertOk()
        ->assertJsonPath('data.is_active', false);
    parcelAs($this->admin, ClientApp::Admin)->getJson('/api/v1/admin/package-types')->assertOk()->assertJsonCount(5, 'data');

    expect(AdminAuditLog::query()->where('action', 'like', 'package_type.%')->pluck('action')->all())->toBe(['package_type.created', 'package_type.updated']);
    parcelAs($this->customer)->getJson('/api/v1/admin/package-types')->assertForbidden();
});
