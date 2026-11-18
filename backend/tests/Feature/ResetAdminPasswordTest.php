<?php

use App\Enums\ClientApp;
use App\Enums\RoleName;
use App\Models\AdminAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('sets a new admin password from the prompt, signs out every session and audits it', function () {
    $admin = makeUser(RoleName::SuperAdmin);
    tokenFor($admin, ClientApp::Admin);

    $this->artisan('admin:reset-password', ['email' => strtoupper($admin->email)])
        ->expectsQuestion('New password (at least 12 characters, letters and numbers)', 'NewAdminPass2026')
        ->expectsQuestion('Repeat the new password', 'NewAdminPass2026')
        ->assertSuccessful();

    $admin->refresh();
    expect(Hash::check('NewAdminPass2026', $admin->password))->toBeTrue()
        ->and($admin->tokens()->count())->toBe(0)
        ->and(AdminAuditLog::query()->where('action', 'admin.password_reset_cli')->sole()->subject_id)->toBe($admin->uuid);

    $this->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'NewAdminPass2026'])->assertOk();
});

it('reads the password from SUPER_ADMIN_PASSWORD with --from-env', function () {
    $admin = makeUser(RoleName::Admin);
    config(['wegodeliva.super_admin.password' => 'FromEnvironment2026']);

    $this->artisan('admin:reset-password', ['email' => $admin->email, '--from-env' => true])
        ->expectsOutputToContain('remove SUPER_ADMIN_PASSWORD')
        ->assertSuccessful();

    expect(Hash::check('FromEnvironment2026', $admin->fresh()->password))->toBeTrue();
});

it('refuses weak, mismatched and missing passwords without changing anything', function () {
    $admin = makeUser(RoleName::SuperAdmin);
    $before = $admin->password;

    $this->artisan('admin:reset-password', ['email' => $admin->email])
        ->expectsQuestion('New password (at least 12 characters, letters and numbers)', 'short1')
        ->expectsQuestion('Repeat the new password', 'short1')
        ->assertFailed();
    $this->artisan('admin:reset-password', ['email' => $admin->email])
        ->expectsQuestion('New password (at least 12 characters, letters and numbers)', 'NewAdminPass2026')
        ->expectsQuestion('Repeat the new password', 'Different2026xx')
        ->expectsOutput('The passwords do not match.')
        ->assertFailed();
    config(['wegodeliva.super_admin.password' => null]);
    $this->artisan('admin:reset-password', ['email' => $admin->email, '--from-env' => true])
        ->expectsOutput('SUPER_ADMIN_PASSWORD is not set.')
        ->assertFailed();

    expect($admin->fresh()->password)->toBe($before)
        ->and(AdminAuditLog::query()->count())->toBe(0);
});

it('does not reset non-admin or unknown accounts', function () {
    $customer = makeUser(RoleName::Customer);

    $this->artisan('admin:reset-password', ['email' => $customer->email])
        ->expectsOutput('No admin account has that email address.')
        ->assertFailed();
    $this->artisan('admin:reset-password', ['email' => 'ghost@example.com'])
        ->expectsOutput('No admin account has that email address.')
        ->assertFailed();
});

it('refuses to prompt when the command is not interactive', function () {
    $admin = makeUser(RoleName::SuperAdmin);

    $this->artisan('admin:reset-password', ['email' => $admin->email, '--no-interaction' => true])
        ->expectsOutputToContain('use --from-env')
        ->assertFailed();
});

it('pins the search_path of the audit-log trigger function', function () {
    $config = DB::scalar("select array_to_string(proconfig, ',') from pg_proc where proname = 'admin_audit_log_append_only'");

    expect($config)->toBe('search_path=""');
});
