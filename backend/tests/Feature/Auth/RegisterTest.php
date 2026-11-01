<?php

use App\Enums\RoleName;
use App\Mail\OtpCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());

function registration(array $overrides = []): array
{
    return array_merge([
        'name' => 'Tolu Adeyemi',
        'email' => 'Tolu@Example.com',
        'phone' => '0803 123 4567',
        'password' => 'secret123',
        'accept_terms' => true,
    ], $overrides);
}

it('registers a customer, normalises email and phone, and emails a code', function () {
    $response = $this->postJson('/api/v1/auth/register', registration());

    $response->assertCreated()
        ->assertJsonPath('data.user.email', 'tolu@example.com')
        ->assertJsonPath('data.otp.expires_in', 600)
        ->assertJsonPath('data.otp.resend_in', 60);

    $user = User::query()->where('email', 'tolu@example.com')->firstOrFail();
    expect($user->phone)->toBe('+2348031234567')
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->phone_verified_at)->toBeNull()
        ->and($user->roleNames())->toBe(['customer'])
        ->and($user->referral_code)->toMatch('/^[A-Z2-9]{8}$/');

    Mail::assertQueued(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->hasTo('tolu@example.com'));
});

it('ignores any role the client sends: sign-up is always customer', function (string $field, mixed $value) {
    $this->postJson('/api/v1/auth/register', registration([$field => $value]))->assertCreated();

    $user = User::query()->where('email', 'tolu@example.com')->firstOrFail();
    expect($user->roleNames())->toBe(['customer'])
        ->and($user->isAdmin())->toBeFalse()
        ->and($user->status->value)->toBe('active')
        ->and($user->kyc_tier)->toBe(1);
})->with([
    'role' => ['role', 'super_admin'],
    'roles' => ['roles', ['admin', 'rider']],
    'status' => ['status', 'active'],
    'kyc_tier' => ['kyc_tier', 3],
    'email_verified_at' => ['email_verified_at', '2026-01-01'],
]);

it('requires name, email, phone and accepted terms', function () {
    $error = expectApiError($this->postJson('/api/v1/auth/register', []), 400, 'validation_failed');

    expect($error['fields'])->toHaveKeys(['name', 'email', 'phone', 'accept_terms'])
        ->not->toHaveKey('password');
});

it('allows OTP-only sign-up without a password', function () {
    $this->postJson('/api/v1/auth/register', registration(['password' => null]))->assertCreated();

    expect(User::query()->where('email', 'tolu@example.com')->value('password'))->toBeNull();
});

it('rejects invalid and non-Nigerian phone numbers', function (string $phone) {
    $error = expectApiError($this->postJson('/api/v1/auth/register', registration(['phone' => $phone])), 400, 'validation_failed');

    expect($error['fields'])->toHaveKey('phone');
})->with(['12345', '+447911123456', '0603 123 4567']);

it('rejects duplicate email and phone, whatever their format', function () {
    makeUser()->forceFill(['email' => 'tolu@example.com', 'phone' => '+2348031234567'])->save();

    $error = expectApiError($this->postJson('/api/v1/auth/register', registration()), 400, 'validation_failed');

    expect($error['fields'])->toHaveKeys(['email', 'phone']);
});

it('rejects weak passwords', function () {
    $error = expectApiError($this->postJson('/api/v1/auth/register', registration(['password' => 'short'])), 400, 'validation_failed');

    expect($error['fields'])->toHaveKey('password');
});

it('records who referred the new customer', function () {
    $referrer = makeUser();

    $this->postJson('/api/v1/auth/register', registration(['referral_code' => strtolower($referrer->referral_code)]))
        ->assertCreated();

    expect(User::query()->where('email', 'tolu@example.com')->value('referred_by_user_id'))->toBe($referrer->id);
});

it('rejects unknown referral codes', function () {
    $error = expectApiError($this->postJson('/api/v1/auth/register', registration(['referral_code' => 'NOPE1234'])), 400, 'validation_failed');

    expect($error['fields'])->toHaveKey('referral_code');
});

it('rate-limits sign-ups per IP', function () {
    config(['wegodeliva.rate_limits.register_per_ip_per_hour' => 2]);

    $this->postJson('/api/v1/auth/register', registration())->assertCreated();
    $this->postJson('/api/v1/auth/register', registration(['email' => 'b@example.com', 'phone' => '08031234568']))->assertCreated();

    expectApiError($this->postJson('/api/v1/auth/register', registration(['email' => 'c@example.com', 'phone' => '08031234569'])), 429, 'rate_limited');
});

it('does not assign admin roles through registration even for an admin email', function () {
    $this->postJson('/api/v1/auth/register', registration(['email' => 'admin@wegodeliva.com']))->assertCreated();

    expect(User::query()->where('email', 'admin@wegodeliva.com')->firstOrFail()->hasRole(RoleName::SuperAdmin))->toBeFalse();
});
