<?php

use App\Enums\ClientApp;
use App\Enums\RoleName;
use App\Enums\VendorStatus;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Zone;
use Database\Seeders\CitySeeder;
use Database\Seeders\VendorTypeSeeder;

beforeEach(function () {
    $this->seed([VendorTypeSeeder::class, CitySeeder::class]);
    $this->admin = makeUser(RoleName::Admin);
    $this->token = tokenFor($this->admin, ClientApp::Admin);
});

function newVendorPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'vendor_type' => 'food',
        'name' => "Mama's Kitchen",
        'address_line' => '12 Herbert Macaulay Way, Yaba',
        'lat' => 6.5095,
        'lng' => 3.3711,
        'owner' => ['email' => 'Owner@Example.com', 'name' => 'Ada Owner', 'phone' => '0803 555 0000'],
    ], $overrides);
}

it('creates a pending vendor and a new owner account without a password', function () {
    $response = $this->withToken($this->token)->postJson('/api/v1/admin/vendors', newVendorPayload())
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending_approval')
        ->assertJsonPath('data.slug', 'mamas-kitchen')
        ->assertJsonPath('data.members.0.email', 'owner@example.com')
        ->assertJsonPath('data.members.0.role', 'owner');

    $owner = User::query()->where('email', 'owner@example.com')->sole();
    expect($owner->password)->toBeNull()
        ->and($owner->phone)->toBe('+2348035550000')
        ->and($owner->roleNames())->toBe(['vendor_owner'])
        ->and(Vendor::query()->sole()->city_id)->not->toBeNull();

    $entry = AdminAuditLog::query()->where('action', 'vendor.created')->sole();
    expect($entry->subject_id)->toBe($response->json('data.id'))->and($entry->actor_user_id)->toBe($this->admin->id);
});

it('reuses an existing account as owner and keeps slugs unique', function () {
    $existing = makeUser(RoleName::Customer);
    $this->withToken($this->token)->postJson('/api/v1/admin/vendors', newVendorPayload(['owner' => ['email' => $existing->email, 'name' => null, 'phone' => null]]))
        ->assertCreated();
    $this->withToken($this->token)->postJson('/api/v1/admin/vendors', newVendorPayload(['owner' => ['email' => $existing->email]]))
        ->assertCreated()
        ->assertJsonPath('data.slug', 'mamas-kitchen-2');

    expect($existing->fresh()->roleNames())->toEqualCanonicalizing(['customer', 'vendor_owner'])
        ->and(User::query()->count())->toBe(2);
});

it('needs owner name and an unused phone when the owner has no account', function () {
    $error = expectApiError($this->withToken($this->token)->postJson('/api/v1/admin/vendors', newVendorPayload(['owner' => ['name' => null, 'phone' => null]])), 400, 'validation_failed');
    expect($error['fields'])->toHaveKeys(['owner.name', 'owner.phone']);

    makeUser()->forceFill(['phone' => '+2348035550000'])->save();
    $error = expectApiError($this->withToken($this->token)->postJson('/api/v1/admin/vendors', newVendorPayload()), 400, 'validation_failed');
    expect($error['fields'])->toHaveKey('owner.phone')
        ->and(Vendor::query()->count())->toBe(0);
});

it('approves and suspends vendors, with a reason for suspension, and audits both', function () {
    $vendor = Vendor::factory()->status(VendorStatus::PendingApproval)->create();
    $base = "/api/v1/admin/vendors/{$vendor->uuid}";

    $this->withToken($this->token)->postJson("{$base}/approve")->assertOk()->assertJsonPath('data.status', 'active');
    expect($vendor->fresh()->approved_at)->not->toBeNull();
    expectApiError($this->withToken($this->token)->postJson("{$base}/approve"), 409, 'vendor_status_unchanged');

    expectApiError($this->withToken($this->token)->postJson("{$base}/suspend"), 400, 'validation_failed');
    $this->withToken($this->token)->postJson("{$base}/suspend", ['reason' => 'Repeated late orders'])
        ->assertOk()->assertJsonPath('data.status', 'suspended');

    expect(AdminAuditLog::query()->where('subject_id', $vendor->uuid)->pluck('action')->all())
        ->toEqualCanonicalizing(['vendor.approved', 'vendor.suspended'])
        ->and(AdminAuditLog::query()->where('action', 'vendor.suspended')->sole()->after)->toEqual(['status' => 'suspended', 'reason' => 'Repeated late orders']);
});

it('lists vendors by status and shows details', function () {
    $pending = Vendor::factory()->status(VendorStatus::PendingApproval)->create();
    Vendor::factory()->create();

    $this->withToken($this->token)->getJson('/api/v1/admin/vendors?status=pending_approval')
        ->assertOk()->assertJsonPath('data.*.id', [$pending->uuid]);
    $this->withToken($this->token)->getJson("/api/v1/admin/vendors/{$pending->uuid}")
        ->assertOk()->assertJsonPath('data.status', 'pending_approval');
});

it('sets delivery zones with optional fee overrides', function () {
    $vendor = Vendor::factory()->create();
    $zones = Zone::query()->limit(2)->get();

    $this->withToken($this->token)->putJson("/api/v1/admin/vendors/{$vendor->uuid}/zones", ['zones' => [
        ['zone_id' => $zones[0]->uuid, 'delivery_fee_kobo' => 50000],
        ['zone_id' => $zones[1]->uuid],
    ]])->assertOk()
        ->assertJsonCount(2, 'data.zones')
        ->assertJsonPath('data.zones.0.delivery_fee_kobo', 50000);

    $this->withToken($this->token)->putJson("/api/v1/admin/vendors/{$vendor->uuid}/zones", ['zones' => []])
        ->assertOk()->assertJsonCount(0, 'data.zones');
    expectApiError($this->withToken($this->token)->putJson("/api/v1/admin/vendors/{$vendor->uuid}/zones", ['zones' => [['zone_id' => '00000000-0000-0000-0000-000000000000']]]), 400, 'validation_failed');
});

it('is for admins only', function (RoleName $role) {
    $user = makeUser($role);
    $vendor = Vendor::factory()->status(VendorStatus::PendingApproval)->create();

    expectApiError($this->withToken(tokenFor($user, ClientApp::Admin))->postJson("/api/v1/admin/vendors/{$vendor->uuid}/approve"), 403, 'forbidden');
    expect($vendor->fresh()->status)->toBe(VendorStatus::PendingApproval);
})->with([RoleName::ZoneAdmin]);

it('refuses non-admin-app tokens', function () {
    $vendor = Vendor::factory()->create();
    $owner = makeUser(RoleName::VendorOwner);
    $vendor->members()->attach($owner->id, ['role' => 'owner']);

    expectApiError($this->withToken(tokenFor($owner, ClientApp::Vendor))->getJson('/api/v1/admin/vendors'), 403, 'forbidden');
});
