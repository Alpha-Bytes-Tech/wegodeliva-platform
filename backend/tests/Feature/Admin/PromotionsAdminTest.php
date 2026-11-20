<?php

use App\Enums\ClientApp;
use App\Enums\RoleName;
use App\Models\AdminAuditLog;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Fee;
use App\Models\Product;
use App\Models\Vendor;
use Database\Seeders\CitySeeder;
use Database\Seeders\VendorTypeSeeder;

beforeEach(function () {
    $this->seed([VendorTypeSeeder::class, CitySeeder::class]);
    $this->admin = makeUser(RoleName::Admin);
    $this->token = tokenFor($this->admin, ClientApp::Admin);
});

function couponPayload(array $overrides = []): array
{
    return array_merge([
        'code' => ' welcome10 ',
        'description' => 'Launch offer',
        'discount_type' => 'percent',
        'value' => 1000,
        'max_discount_kobo' => 100000,
        'min_order_kobo' => 200000,
        'applies_to' => 'order_subtotal',
        'scope' => 'all',
        'max_uses_per_user' => 1,
        'first_order_only' => true,
    ], $overrides);
}

it('creates coupons with upper-case codes, vendor-funded by default, and audits them', function () {
    $response = $this->withToken($this->token)->postJson('/api/v1/admin/coupons', couponPayload())
        ->assertCreated()
        ->assertJsonPath('data.code', 'WELCOME10')
        ->assertJsonPath('data.funded_by', 'vendor')
        ->assertJsonPath('data.first_order_only', true)
        ->assertJsonPath('data.uses', 0)
        ->assertJsonPath('data.vendor_ids', []);

    $coupon = Coupon::query()->sole();
    expect($coupon->created_by)->toBe($this->admin->id)
        ->and(AdminAuditLog::query()->where('action', 'coupon.created')->sole()->subject_id)->toBe($response->json('data.id'));

    $error = expectApiError($this->withToken($this->token)->postJson('/api/v1/admin/coupons', couponPayload(['code' => 'Welcome10'])), 400, 'validation_failed');
    expect($error['fields'])->toHaveKey('code');
});

it('links coupons to a vendor type, vendors or products', function () {
    $vendor = Vendor::factory()->create();
    $product = Product::factory()->for($vendor)->create();

    $this->withToken($this->token)->postJson('/api/v1/admin/coupons', couponPayload(['code' => 'FOOD', 'scope' => 'vendor_type', 'vendor_type' => 'food']))
        ->assertCreated()->assertJsonPath('data.vendor_type', 'food');
    $this->withToken($this->token)->postJson('/api/v1/admin/coupons', couponPayload(['code' => 'SHOP', 'scope' => 'vendors', 'vendor_ids' => [$vendor->uuid]]))
        ->assertCreated()->assertJsonPath('data.vendor_ids', [$vendor->uuid]);
    $id = $this->withToken($this->token)->postJson('/api/v1/admin/coupons', couponPayload(['code' => 'RICE', 'scope' => 'products', 'product_ids' => [$product->uuid]]))
        ->assertCreated()->assertJsonPath('data.product_ids', [$product->uuid])->json('data.id');

    // Changing the scope drops the old links.
    $this->withToken($this->token)->patchJson("/api/v1/admin/coupons/{$id}", ['scope' => 'all'])
        ->assertOk()->assertJsonPath('data.scope', 'all')->assertJsonPath('data.product_ids', []);
});

it('rejects inconsistent coupons', function (array $overrides, string $field) {
    $error = expectApiError($this->withToken($this->token)->postJson('/api/v1/admin/coupons', couponPayload($overrides)), 400, 'validation_failed');

    expect($error['fields'])->toHaveKey($field);
})->with([
    'percent above 100%' => [['value' => 10001], 'value'],
    'bad code' => [['code' => 'no spaces!'], 'code'],
    'vendor scope without vendors' => [['scope' => 'vendors'], 'vendor_ids'],
    'product scope without products' => [['scope' => 'products'], 'product_ids'],
    'vendor type scope without a type' => [['scope' => 'vendor_type'], 'vendor_type'],
    'ends before it starts' => [['starts_at' => '2026-12-10', 'ends_at' => '2026-12-01'], 'ends_at'],
    'vendor-funded delivery coupon' => [['applies_to' => 'delivery_fee'], 'funded_by'],
]);

it('edits coupons partially, keeps the code and re-checks the whole coupon', function () {
    $id = $this->withToken($this->token)->postJson('/api/v1/admin/coupons', couponPayload())->json('data.id');
    $url = "/api/v1/admin/coupons/{$id}";

    $this->withToken($this->token)->patchJson($url, ['is_active' => false, 'ends_at' => '2027-01-31T23:59:59Z'])
        ->assertOk()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.value', 1000)
        ->assertJsonPath('data.ends_at', '2027-01-31T23:59:59+00:00');

    expectApiError($this->withToken($this->token)->patchJson($url, ['code' => 'OTHER']), 400, 'validation_failed');
    $error = expectApiError($this->withToken($this->token)->patchJson($url, ['applies_to' => 'delivery_fee']), 400, 'validation_failed');
    expect($error['fields'])->toHaveKey('funded_by');
    $this->withToken($this->token)->patchJson($url, ['applies_to' => 'delivery_fee', 'funded_by' => 'platform'])->assertOk();

    expect(AdminAuditLog::query()->where('action', 'coupon.updated')->count())->toBe(2);
});

it('lists coupons with their counted uses and filters them', function () {
    $this->withToken($this->token)->postJson('/api/v1/admin/coupons', couponPayload(['code' => 'ALPHA']))->assertCreated();
    $this->withToken($this->token)->postJson('/api/v1/admin/coupons', couponPayload(['code' => 'BETA', 'is_active' => false]))->assertCreated();
    $alpha = Coupon::query()->where('code', 'ALPHA')->sole();
    $customer = makeUser();
    CouponRedemption::query()->create(['coupon_id' => $alpha->id, 'user_id' => $customer->id, 'discount_kobo' => 5000]);
    CouponRedemption::query()->create(['coupon_id' => $alpha->id, 'user_id' => $customer->id, 'discount_kobo' => 5000])->forceFill(['released_at' => now()])->save();

    $this->withToken($this->token)->getJson('/api/v1/admin/coupons?active=1')
        ->assertOk()->assertJsonPath('data.*.code', ['ALPHA'])->assertJsonPath('data.0.uses', 1);
    $this->withToken($this->token)->getJson('/api/v1/admin/coupons?q=be')->assertOk()->assertJsonPath('data.*.code', ['BETA']);
    $this->withToken($this->token)->getJson("/api/v1/admin/coupons/{$alpha->uuid}")->assertOk()->assertJsonPath('data.uses', 1);
});

it('manages service fees and assigns them and a commission rate to vendors', function () {
    $fee = $this->withToken($this->token)->postJson('/api/v1/admin/fees', ['name' => 'Service charge', 'type' => 'percent', 'value' => 250, 'beneficiary' => 'platform'])
        ->assertCreated()->assertJsonPath('data.is_active', true)->json('data.id');
    expectApiError($this->withToken($this->token)->postJson('/api/v1/admin/fees', ['name' => 'Too much', 'type' => 'percent', 'value' => 10001, 'beneficiary' => 'platform']), 400, 'validation_failed');

    $this->withToken($this->token)->patchJson("/api/v1/admin/fees/{$fee}", ['value' => 300])
        ->assertOk()->assertJsonPath('data.value', 300)->assertJsonPath('data.name', 'Service charge');
    $this->withToken($this->token)->getJson('/api/v1/admin/fees')->assertOk()->assertJsonPath('data.*.id', [$fee]);

    $vendor = Vendor::factory()->create();
    $url = "/api/v1/admin/vendors/{$vendor->uuid}/pricing";
    $this->withToken($this->token)->patchJson($url, ['commission_rate_bp' => 400, 'fee_ids' => [$fee]])
        ->assertOk()
        ->assertJsonPath('data.commission_rate_bp', 400)
        ->assertJsonPath('data.fees.0.id', $fee);

    // Sending only the rate leaves the fees alone; null returns to the platform default.
    $this->withToken($this->token)->patchJson($url, ['commission_rate_bp' => null])
        ->assertOk()->assertJsonPath('data.commission_rate_bp', null)->assertJsonPath('data.fees.0.id', $fee);
    $this->withToken($this->token)->patchJson($url, ['fee_ids' => []])->assertOk()->assertJsonPath('data.fees', []);

    expect(AdminAuditLog::query()->whereIn('action', ['fee.created', 'fee.updated', 'vendor.pricing_updated'])->count())->toBe(5)
        ->and(Fee::query()->sole()->value)->toBe(300);
});

it('keeps coupons, fees and pricing away from non-admins', function () {
    $zoneAdmin = tokenFor(makeUser(RoleName::ZoneAdmin), ClientApp::Admin);
    $vendor = Vendor::factory()->create();

    expectApiError($this->withToken($zoneAdmin)->postJson('/api/v1/admin/coupons', couponPayload()), 403, 'forbidden');
    expectApiError($this->withToken($zoneAdmin)->getJson('/api/v1/admin/coupons'), 403, 'forbidden');
    expectApiError($this->withToken($zoneAdmin)->getJson('/api/v1/admin/fees'), 403, 'forbidden');
    expectApiError($this->withToken($zoneAdmin)->patchJson("/api/v1/admin/vendors/{$vendor->uuid}/pricing", ['commission_rate_bp' => 0]), 403, 'forbidden');
    expect(Coupon::query()->count())->toBe(0)->and($vendor->fresh()->commission_rate_bp)->toBeNull();
});
