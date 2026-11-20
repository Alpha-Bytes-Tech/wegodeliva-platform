<?php

use App\Enums\ClientApp;
use App\Enums\RoleName;
use App\Enums\VendorMemberRole;
use App\Enums\VendorStatus;
use App\Models\Vendor;

/** A vendor-app token for a new user who works at $vendor with $role. */
function vendorToken(Vendor $vendor, VendorMemberRole $role = VendorMemberRole::Owner): string
{
    $user = makeUser($role === VendorMemberRole::Owner ? RoleName::VendorOwner : RoleName::VendorStaff);
    $vendor->members()->attach($user->id, ['role' => $role->value]);

    return tokenFor($user, ClientApp::Vendor);
}

it("lists the stores the user works at, with the user's role", function () {
    $owner = makeUser(RoleName::VendorOwner);
    $mine = Vendor::factory()->withMember($owner)->create(['name' => 'A Kitchen']);
    $other = Vendor::factory()->withMember($owner, VendorMemberRole::Staff)->create(['name' => 'B Kitchen']);
    Vendor::factory()->create(['name' => 'Not mine']);

    $this->withToken(tokenFor($owner, ClientApp::Vendor))->getJson('/api/v1/vendor/stores')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$mine->uuid, $other->uuid])
        ->assertJsonPath('data.*.my_role', ['owner', 'staff'])
        ->assertJsonPath('data.0.status', 'active');
});

it("answers 404 for a store the user doesn't work at", function () {
    $vendor = Vendor::factory()->create();
    $token = vendorToken(Vendor::factory()->create());

    expectApiError($this->withToken($token)->getJson("/api/v1/vendor/stores/{$vendor->uuid}"), 404, 'not_found');
    expectApiError($this->withToken($token)->patchJson("/api/v1/vendor/stores/{$vendor->uuid}", ['name' => 'Taken']), 404, 'not_found');
    expectApiError($this->withToken($token)->putJson("/api/v1/vendor/stores/{$vendor->uuid}/status", ['state' => 'closed']), 404, 'not_found');
});

it('lets the owner change settings but not status, commission or location', function () {
    $vendor = Vendor::factory()->status(VendorStatus::PendingApproval)->create();
    $token = vendorToken($vendor);

    $this->withToken($token)->patchJson("/api/v1/vendor/stores/{$vendor->uuid}", [
        'name' => 'New Name',
        'accepts_pickup' => true,
        'min_order_kobo' => 150000,
        'delivery_fee_mode' => 'per_km',
        'vat_registered' => true,
        'tin' => '12345678-0001',
        'status' => 'active',
        'commission_rate_bp' => 0,
        'lat' => 9.0,
        'approved_at' => '2026-01-01',
    ])->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.accepts_pickup', true)
        ->assertJsonPath('data.min_order_kobo', 150000)
        ->assertJsonPath('data.delivery_fee_mode', 'per_km')
        ->assertJsonPath('data.status', 'pending_approval')
        ->assertJsonPath('data.commission_rate_bp', null)
        ->assertJsonPath('data.approved_at', null);

    expect((float) $vendor->fresh()->lat)->toBe(6.515);
});

it('keeps business settings owner-only', function () {
    $vendor = Vendor::factory()->create();
    $staff = vendorToken($vendor, VendorMemberRole::Staff);

    expectApiError($this->withToken($staff)->patchJson("/api/v1/vendor/stores/{$vendor->uuid}", ['min_order_kobo' => 1]), 403, 'forbidden');
    $this->withToken($staff)->getJson("/api/v1/vendor/stores/{$vendor->uuid}")->assertOk()->assertJsonCount(1, 'data.members');
});

it('validates settings', function () {
    $vendor = Vendor::factory()->create();
    $error = expectApiError($this->withToken(vendorToken($vendor))->patchJson("/api/v1/vendor/stores/{$vendor->uuid}", [
        'delivery_fee_mode' => 'free', 'min_order_kobo' => -1, 'tin' => 'abc', 'phone' => '123',
        'logo_path' => 'vendors/someone-else/store/logo.png', 'cover_path' => "vendors/{$vendor->uuid}/store/../../x.png",
    ]), 400, 'validation_failed');

    expect($error['fields'])->toHaveKeys(['delivery_fee_mode', 'min_order_kobo', 'tin', 'phone', 'logo_path', 'cover_path']);

    $this->withToken(vendorToken($vendor))->patchJson("/api/v1/vendor/stores/{$vendor->uuid}", ['logo_path' => "vendors/{$vendor->uuid}/store/logo.png"])
        ->assertOk()->assertJsonPath('data.logo_path', "vendors/{$vendor->uuid}/store/logo.png");
});

it('lets staff set busy or closed for a while, then return to the schedule', function () {
    $vendor = Vendor::factory()->create();
    $token = vendorToken($vendor, VendorMemberRole::Staff);
    $url = "/api/v1/vendor/stores/{$vendor->uuid}/status";

    $this->withToken($token)->putJson($url, ['state' => 'busy', 'minutes' => 30])
        ->assertOk()
        ->assertJsonPath('data.open_state', 'busy')
        ->assertJsonPath('data.manual_state', 'busy');

    $this->travel(31)->minutes();
    expect($vendor->fresh()->openState()->value)->toBe('open');

    $this->withToken($token)->putJson($url, ['state' => 'closed'])->assertJsonPath('data.open_state', 'closed');
    $this->withToken($token)->putJson($url, ['state' => 'schedule'])
        ->assertJsonPath('data.open_state', 'open')
        ->assertJsonPath('data.manual_state', null);

    expectApiError($this->withToken($token)->putJson($url, ['state' => 'schedule', 'minutes' => 10]), 400, 'validation_failed');
    expectApiError($this->withToken($token)->putJson($url, ['state' => 'sleeping']), 400, 'validation_failed');
});

it('replaces opening hours, including split and overnight shifts', function () {
    $vendor = Vendor::factory()->create();
    $token = vendorToken($vendor, VendorMemberRole::Staff);

    $this->withToken($token)->putJson("/api/v1/vendor/stores/{$vendor->uuid}/hours", ['hours' => [
        ['weekday' => 1, 'opens_at' => '08:00', 'closes_at' => '11:00'],
        ['weekday' => 1, 'opens_at' => '17:00', 'closes_at' => '02:00'],
    ]])->assertOk()
        ->assertJsonCount(2, 'data.hours')
        ->assertJsonPath('data.hours.1.closes_at', '02:00');

    $error = expectApiError($this->withToken($token)->putJson("/api/v1/vendor/stores/{$vendor->uuid}/hours", ['hours' => [
        ['weekday' => 7, 'opens_at' => '8am', 'closes_at' => '08:00'],
    ]]), 400, 'validation_failed');
    expect($error['fields'])->toHaveKeys(['hours.0.weekday', 'hours.0.opens_at']);

    // An empty list closes the store until hours are set again.
    $this->withToken($token)->putJson("/api/v1/vendor/stores/{$vendor->uuid}/hours", ['hours' => []])
        ->assertOk()
        ->assertJsonPath('data.open_state', 'closed');
});

it('needs a vendor-app token', function () {
    $vendor = Vendor::factory()->create();
    $owner = makeUser(RoleName::VendorOwner, RoleName::Customer);
    $vendor->members()->attach($owner->id, ['role' => 'owner']);

    expectApiError($this->withToken(tokenFor($owner, ClientApp::Customer))->getJson('/api/v1/vendor/stores'), 403, 'forbidden');
});
