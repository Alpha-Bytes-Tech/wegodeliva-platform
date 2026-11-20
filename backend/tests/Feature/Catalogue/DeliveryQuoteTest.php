<?php

use App\Enums\VendorStatus;
use App\Models\Vendor;
use App\Models\Zone;
use App\Services\Catalogue\ZoneLocator;
use Database\Seeders\CitySeeder;
use Database\Seeders\VendorTypeSeeder;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => $this->seed([VendorTypeSeeder::class, CitySeeder::class]));

/** Pretends the drop-off point lies in $zone (zone boundaries need PostGIS and drawn polygons). */
function dropOffIn(?Zone $zone): void
{
    app()->instance(ZoneLocator::class, new class($zone) extends ZoneLocator
    {
        public function __construct(private readonly ?Zone $zone) {}

        public function zoneAt(float $lat, float $lng): ?Zone
        {
            return $this->zone;
        }
    });
}

/** About 1.1 km north of the factory vendor location (6.515, 3.385). */
function quoteFor(Vendor $vendor, array $point = ['lat' => 6.525, 'lng' => 3.385]): TestResponse
{
    return test()->getJson("/api/v1/catalogue/vendors/{$vendor->slug}/delivery-quote?".http_build_query($point));
}

it('quotes the vendor\'s own flat and per-km fees with a delivery time range', function () {
    $flat = Vendor::factory()->create(['delivery_fee_mode' => 'flat', 'delivery_fee_kobo' => 70000, 'base_delivery_fee_kobo' => 10000, 'prep_time_minutes' => 15]);
    $perKm = Vendor::factory()->create(['delivery_fee_mode' => 'per_km', 'delivery_fee_kobo' => 20000, 'base_delivery_fee_kobo' => 0]);

    $response = quoteFor($flat)->assertOk()
        ->assertJsonPath('data.deliverable', true)
        ->assertJsonPath('data.reason', null)
        ->assertJsonPath('data.fee.amount_kobo', 80000)
        ->assertJsonPath('data.fee.formatted', '₦800.00');
    // 1,112 m straight × 1.3 ≈ 1,446 m road: 5 minutes' ride after 15 minutes' prep.
    expect($response->json('data.distance_m'))->toBe(1446)
        ->and($response->json('data.eta_minutes'))->toBe(['min' => 20, 'max' => 30]);

    // ₦200/km × 1.446 km = ₦289.20
    quoteFor($perKm)->assertOk()->assertJsonPath('data.fee.amount_kobo', 28920);
});

it('uses the fee of the vendor zone the drop-off is in, override first', function () {
    $zone = Zone::query()->where('is_campus', false)->firstOr(fn () => Zone::query()->firstOrFail());
    $zone->forceFill(['default_delivery_fee_kobo' => 45000, 'is_campus' => false])->save();
    $vendor = Vendor::factory()->create(['delivery_fee_mode' => 'flat', 'delivery_fee_kobo' => 99900, 'delivery_radius_m' => 100]);
    dropOffIn($zone);

    // Outside the 100 m radius, but the zone is served: the zone default applies.
    quoteFor($vendor)->assertOk()->assertJsonPath('data.deliverable', false)->assertJsonPath('data.reason', 'out_of_range');
    $vendor->zones()->attach($zone->id);
    quoteFor($vendor)->assertOk()->assertJsonPath('data.deliverable', true)->assertJsonPath('data.fee.amount_kobo', 45000);

    $vendor->zones()->updateExistingPivot($zone->id, ['delivery_fee_kobo' => 30000]);
    quoteFor($vendor)->assertOk()->assertJsonPath('data.fee.amount_kobo', 30000);
});

it('charges the campus fee when vendor and drop-off share a campus zone, if campus mode is on', function () {
    $campus = Zone::query()->where('is_campus', true)->firstOrFail();
    $campus->forceFill(['default_delivery_fee_kobo' => 20000])->save();
    $vendor = Vendor::factory()->create(['delivery_fee_mode' => 'flat', 'delivery_fee_kobo' => 99900]);
    $vendor->forceFill(['zone_id' => $campus->id])->save();
    dropOffIn($campus);

    quoteFor($vendor)->assertOk()->assertJsonPath('data.fee.amount_kobo', 20000);

    config(['wegodeliva.pricing.campus_mode' => false]);
    quoteFor($vendor)->assertOk()->assertJsonPath('data.fee.amount_kobo', 99900);
});

it('reports pickup-only vendors and drop-offs out of range', function () {
    $pickup = Vendor::factory()->create(['accepts_delivery' => false]);
    $vendor = Vendor::factory()->create();

    quoteFor($pickup)->assertOk()
        ->assertJsonPath('data.deliverable', false)
        ->assertJsonPath('data.reason', 'pickup_only')
        ->assertJsonPath('data.fee', null)
        ->assertJsonPath('data.eta_minutes', null);
    quoteFor($vendor, ['lat' => 6.6, 'lng' => 3.385])->assertOk()->assertJsonPath('data.reason', 'out_of_range');
});

it('needs a point and an active vendor', function () {
    $vendor = Vendor::factory()->create();
    $pending = Vendor::factory()->status(VendorStatus::PendingApproval)->create();

    expectApiError($this->getJson("/api/v1/catalogue/vendors/{$vendor->slug}/delivery-quote"), 400, 'validation_failed');
    expectApiError(quoteFor($pending), 404, 'not_found');
});
