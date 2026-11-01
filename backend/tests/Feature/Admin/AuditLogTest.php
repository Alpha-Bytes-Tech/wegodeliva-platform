<?php

use App\Enums\ClientApp;
use App\Enums\RoleName;
use App\Models\AdminAuditLog;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;

it('lists audit entries newest first for admins', function () {
    $admin = makeUser(RoleName::SuperAdmin);
    app(AuditLogger::class)->record($admin, 'settings.updated', 'setting', 'vat_rate', ['value' => 750], ['value' => 800]);
    app(AuditLogger::class)->record($admin, 'admin.login', 'user', $admin->uuid);

    $this->withToken(tokenFor($admin, ClientApp::Admin))
        ->getJson('/api/v1/admin/audit-log')
        ->assertOk()
        ->assertJsonPath('data.0.action', 'admin.login')
        ->assertJsonPath('data.1.action', 'settings.updated')
        ->assertJsonPath('data.1.after', ['value' => 800])
        ->assertJsonPath('data.0.actor.uuid', $admin->uuid)
        ->assertJsonStructure(['meta' => ['next_cursor', 'per_page']]);
});

it('filters by action and caps the page size at 50', function () {
    $admin = makeUser(RoleName::Admin);
    app(AuditLogger::class)->record($admin, 'a.one');
    app(AuditLogger::class)->record($admin, 'a.two');
    $token = tokenFor($admin, ClientApp::Admin);

    $this->withToken($token)->getJson('/api/v1/admin/audit-log?action=a.one')->assertOk()->assertJsonCount(1, 'data');
    expectApiError($this->withToken($token)->getJson('/api/v1/admin/audit-log?per_page=51'), 400, 'validation_failed');
});

it('refuses tokens issued to other apps, even for admins', function (ClientApp $app) {
    $user = makeUser(RoleName::SuperAdmin, RoleName::Customer, RoleName::Rider, RoleName::VendorOwner);

    expectApiError($this->withToken(tokenFor($user, $app))->getJson('/api/v1/admin/audit-log'), 403, 'forbidden');
})->with([ClientApp::Customer, ClientApp::Rider, ClientApp::Vendor]);

it('refuses zone admins (no audit-log access in spec 02)', function () {
    $zoneAdmin = makeUser(RoleName::ZoneAdmin);

    expectApiError($this->withToken(tokenFor($zoneAdmin, ClientApp::Admin))->getJson('/api/v1/admin/audit-log'), 403, 'forbidden');
});

it('refuses an admin-app token held by someone who is no longer an admin', function () {
    $user = makeUser(RoleName::Customer);

    expectApiError($this->withToken(tokenFor($user, ClientApp::Admin))->getJson('/api/v1/admin/audit-log'), 403, 'forbidden');
});

it('is append-only at the database level', function () {
    $entry = app(AuditLogger::class)->record(null, 'test.entry');

    expect(fn () => AdminAuditLog::query()->whereKey($entry->id)->update(['action' => 'tampered']))->toThrow(QueryException::class);
    expect(fn () => AdminAuditLog::query()->whereKey($entry->id)->delete())->toThrow(QueryException::class);
});
