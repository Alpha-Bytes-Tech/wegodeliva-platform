<?php

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AdminAuditLog;
use App\Models\User;

it('signs in with email and password and returns an app-scoped token', function () {
    $user = makeUser(RoleName::Customer);

    $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123', 'app' => 'customer']);

    $response->assertOk()->assertJsonPath('data.app', 'customer')->assertJsonPath('data.user.roles', ['customer']);
    expect($user->tokens()->sole()->abilities)->toBe(['app:customer']);
});

it('gives the same error for a wrong password and an unknown email', function () {
    $user = makeUser();

    $wrong = expectApiError($this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'nope12345', 'app' => 'customer']), 401, 'invalid_credentials');
    $unknown = expectApiError($this->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'nope12345', 'app' => 'customer']), 401, 'invalid_credentials');

    expect($wrong)->toBe($unknown);
});

it('rejects password login for accounts without a password', function () {
    $user = User::factory()->withoutPassword()->withRole(RoleName::Customer)->create();

    expectApiError($this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'anything1', 'app' => 'customer']), 401, 'invalid_credentials');
});

it('requires a verified email before password login', function () {
    $user = User::factory()->unverified()->withRole(RoleName::Customer)->create();

    expectApiError($this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123', 'app' => 'customer']), 403, 'email_not_verified');
});

it('applies the app role hint', function () {
    $customer = makeUser(RoleName::Customer);

    expectApiError($this->postJson('/api/v1/auth/login', ['email' => $customer->email, 'password' => 'password123', 'app' => 'rider']), 403, 'role_not_allowed');
    expect($customer->tokens()->count())->toBe(0);
});

it('lets a user with several roles sign in to each matching app with separate abilities', function () {
    $user = makeUser(RoleName::Customer, RoleName::Rider);

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123', 'app' => 'customer'])->assertOk();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123', 'app' => 'rider'])->assertOk();

    expect($user->tokens()->pluck('abilities')->all())->toEqualCanonicalizing([['app:customer'], ['app:rider']]);
});

it('refuses suspended accounts', function () {
    $user = User::factory()->status(UserStatus::Suspended)->withRole(RoleName::Customer)->create();

    expectApiError($this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123', 'app' => 'customer']), 403, 'account_suspended');
});

it('rate-limits password attempts per email', function () {
    $user = makeUser();
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-pass1', 'app' => 'customer'])->assertStatus(401);
    }

    $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123', 'app' => 'customer']);
    expectApiError($response, 429, 'rate_limited');
    $response->assertHeader('Retry-After');
});

it('signs admins in to the admin panel with an admin-scoped token and audits it', function () {
    $admin = makeUser(RoleName::SuperAdmin);

    $this->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'password123'])
        ->assertOk()
        ->assertJsonPath('data.app', 'admin');

    expect($admin->tokens()->sole()->abilities)->toBe(['app:admin']);
    $entry = AdminAuditLog::query()->where('action', 'admin.login')->sole();
    expect($entry->actor_user_id)->toBe($admin->id)->and($entry->subject_id)->toBe($admin->uuid);
});

it('keeps non-admins out of the admin panel and audits the attempt', function () {
    $customer = makeUser(RoleName::Customer);

    expectApiError($this->postJson('/api/v1/admin/auth/login', ['email' => $customer->email, 'password' => 'password123']), 403, 'role_not_allowed');
    expectApiError($this->postJson('/api/v1/admin/auth/login', ['email' => $customer->email, 'password' => 'bad-pass1']), 401, 'invalid_credentials');

    expect(AdminAuditLog::query()->where('action', 'admin.login_failed')->count())->toBe(2)
        ->and($customer->tokens()->count())->toBe(0);
});

it('accepts zone admins in the admin panel', function () {
    $zoneAdmin = makeUser(RoleName::ZoneAdmin);

    $this->postJson('/api/v1/admin/auth/login', ['email' => $zoneAdmin->email, 'password' => 'password123'])->assertOk();
});
