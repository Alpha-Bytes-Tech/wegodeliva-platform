<?php

use App\Enums\ClientApp;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Mail\OtpCodeMail;
use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());

/** Requests a code and returns the plain code from the queued email. */
function requestCode(string $email, string $app = 'customer', string $purpose = 'login'): string
{
    test()->postJson('/api/v1/auth/otp/send', compact('email', 'app', 'purpose'))->assertStatus(202);
    $code = null;
    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return (string) $code;
}

it('sends a 6-digit code by email and stores only its hash', function () {
    $user = makeUser();

    $code = requestCode($user->email);

    expect($code)->toMatch('/^\d{6}$/');
    $challenge = OtpChallenge::query()->sole();
    expect($challenge->code_hash)->not->toBe($code)
        ->and($challenge->channel)->toBe('email')
        ->and($challenge->destination)->toBe($user->email)
        ->and($challenge->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(9.9)->toBeLessThanOrEqual(10.0);
});

it('names the requesting app in the email and never includes a link', function () {
    $user = makeUser(RoleName::Rider);
    requestCode($user->email, 'rider');

    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) {
        $mail->assertSeeInText('WeGoDeliva Rider');
        $mail->assertSeeInText($mail->code);
        $mail->assertDontSeeInText('http');
        expect($mail->envelope()->subject)->toContain('WeGoDeliva Rider');

        return true;
    });
});

it('answers the same for unknown emails without sending anything', function () {
    $this->postJson('/api/v1/auth/otp/send', ['email' => 'nobody@example.com', 'app' => 'customer'])
        ->assertStatus(202)
        ->assertJsonPath('data.otp.resend_in', 60);

    Mail::assertNothingQueued();
    expect(OtpChallenge::query()->count())->toBe(0);
});

it('signs in with a valid code, verifies the email and issues an app-scoped token', function () {
    $user = User::factory()->unverified()->withRole(RoleName::Customer)->create();
    $code = requestCode($user->email);

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'email' => strtoupper($user->email), 'code' => $code, 'app' => 'customer', 'device_name' => 'Pixel 9',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.app', 'customer')
        ->assertJsonPath('data.user.uuid', $user->uuid)
        ->assertJsonPath('data.user.email_verified', true)
        ->assertJsonMissingPath('data.user.id')
        ->assertJsonMissingPath('data.user.password');

    expect($user->fresh()->email_verified_at)->not->toBeNull();
    $token = $user->tokens()->sole();
    expect($token->abilities)->toBe(['app:customer'])
        ->and($token->name)->toBe('customer:Pixel 9')
        ->and($token->expires_at)->not->toBeNull();
});

it('consumes a code so it cannot be replayed', function () {
    $user = makeUser();
    $code = requestCode($user->email);
    $payload = ['email' => $user->email, 'code' => $code, 'app' => 'customer'];

    $this->postJson('/api/v1/auth/otp/verify', $payload)->assertOk();
    expectApiError($this->postJson('/api/v1/auth/otp/verify', $payload), 422, 'otp_invalid');
});

it('rejects an expired code', function () {
    $user = makeUser();
    $code = requestCode($user->email);

    $this->travel(11)->minutes();

    expectApiError($this->postJson('/api/v1/auth/otp/verify', ['email' => $user->email, 'code' => $code, 'app' => 'customer']), 422, 'otp_expired');
});

it('locks the code after five wrong attempts, even if the right code follows', function () {
    $user = makeUser();
    $code = requestCode($user->email);
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach ([4, 3, 2, 1] as $remaining) {
        $error = expectApiError($this->postJson('/api/v1/auth/otp/verify', ['email' => $user->email, 'code' => $wrong, 'app' => 'customer']), 422, 'otp_invalid');
        expect($error['meta']['attempts_remaining'])->toBe($remaining);
    }
    expectApiError($this->postJson('/api/v1/auth/otp/verify', ['email' => $user->email, 'code' => $wrong, 'app' => 'customer']), 422, 'otp_attempts_exceeded');
    expectApiError($this->postJson('/api/v1/auth/otp/verify', ['email' => $user->email, 'code' => $code, 'app' => 'customer']), 422, 'otp_attempts_exceeded');

    expect(OtpChallenge::query()->sole()->attempts)->toBe(5);
});

it('makes a new code replace the previous one', function () {
    $user = makeUser();
    $first = requestCode($user->email);
    $this->travel(61)->seconds();
    Mail::fake();
    $second = requestCode($user->email);

    if ($first !== $second) {
        expectApiError($this->postJson('/api/v1/auth/otp/verify', ['email' => $user->email, 'code' => $first, 'app' => 'customer']), 422, 'otp_invalid');
    }
    $this->postJson('/api/v1/auth/otp/verify', ['email' => $user->email, 'code' => $second, 'app' => 'customer'])->assertOk();
});

it('enforces the 60-second resend delay', function () {
    $user = makeUser();
    requestCode($user->email);

    $error = expectApiError($this->postJson('/api/v1/auth/otp/send', ['email' => $user->email, 'app' => 'customer']), 429, 'otp_resend_too_soon');
    expect($error['meta']['retry_after'])->toBeGreaterThan(0)->toBeLessThanOrEqual(60);

    $this->travel(61)->seconds();
    $this->postJson('/api/v1/auth/otp/send', ['email' => $user->email, 'app' => 'customer'])->assertStatus(202);
});

it('limits codes per email address per hour', function () {
    $user = makeUser();
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/otp/send', ['email' => $user->email, 'app' => 'customer'])->assertStatus(202);
        $this->travel(61)->seconds();
    }

    expectApiError($this->postJson('/api/v1/auth/otp/send', ['email' => $user->email, 'app' => 'customer']), 429, 'rate_limited');
});

it('limits codes per IP address per hour across emails', function () {
    config(['wegodeliva.otp.per_ip_per_hour' => 3]);
    foreach (range(1, 3) as $i) {
        $this->postJson('/api/v1/auth/otp/send', ['email' => "u{$i}@example.com", 'app' => 'customer'])->assertStatus(202);
    }

    expectApiError($this->postJson('/api/v1/auth/otp/send', ['email' => 'u4@example.com', 'app' => 'customer']), 429, 'rate_limited');
});

it('limits verification attempts per IP', function () {
    config(['wegodeliva.rate_limits.verify_per_ip_per_hour' => 2]);
    $payload = ['email' => 'x@example.com', 'code' => '123456', 'app' => 'customer'];

    $this->postJson('/api/v1/auth/otp/verify', $payload)->assertStatus(422);
    $this->postJson('/api/v1/auth/otp/verify', $payload)->assertStatus(422);
    expectApiError($this->postJson('/api/v1/auth/otp/verify', $payload), 429, 'rate_limited');
});

it('only lets each app sign in users with a matching role', function (array $roles, string $app, bool $allowed) {
    $user = makeUser(...$roles);
    $code = requestCode($user->email, $app);

    $response = $this->postJson('/api/v1/auth/otp/verify', ['email' => $user->email, 'code' => $code, 'app' => $app]);

    $allowed ? $response->assertOk() : expectApiError($response, 403, 'role_not_allowed');
})->with([
    'customer → customer app' => [[RoleName::Customer], 'customer', true],
    'customer → rider app' => [[RoleName::Customer], 'rider', false],
    'customer → vendor app' => [[RoleName::Customer], 'vendor', false],
    'rider → rider app' => [[RoleName::Rider], 'rider', true],
    'rider → customer app (gains customer role)' => [[RoleName::Rider], 'customer', true],
    'vendor owner → customer app (gains customer role)' => [[RoleName::VendorOwner], 'customer', true],
    'vendor staff → customer app (gains customer role)' => [[RoleName::VendorStaff], 'customer', true],
    'admin → customer app' => [[RoleName::Admin], 'customer', false],
    'vendor owner → vendor app' => [[RoleName::VendorOwner], 'vendor', true],
    'vendor staff → vendor app' => [[RoleName::VendorStaff], 'vendor', true],
    'admin → rider app' => [[RoleName::SuperAdmin], 'rider', false],
]);

it('does not accept OTP sign-in for the admin panel', function () {
    $user = makeUser(RoleName::SuperAdmin);

    $error = expectApiError($this->postJson('/api/v1/auth/otp/verify', ['email' => $user->email, 'code' => '123456', 'app' => 'admin']), 400, 'validation_failed');
    expect($error['fields'])->toHaveKey('app');
});

it('refuses suspended accounts', function () {
    $user = User::factory()->status(UserStatus::Suspended)->withRole(RoleName::Customer)->create();
    $code = requestCode($user->email);

    expectApiError($this->postJson('/api/v1/auth/otp/verify', ['email' => $user->email, 'code' => $code, 'app' => 'customer']), 403, 'account_suspended');
});

it('lets riders awaiting approval sign in to finish onboarding', function () {
    $user = User::factory()->status(UserStatus::PendingApproval)->withRole(RoleName::Rider)->create();
    $code = requestCode($user->email, 'rider');

    $this->postJson('/api/v1/auth/otp/verify', ['email' => $user->email, 'code' => $code, 'app' => 'rider'])
        ->assertOk()
        ->assertJsonPath('data.user.status', 'pending_approval');
});

it('validates the code format', function () {
    $error = expectApiError($this->postJson('/api/v1/auth/otp/verify', ['email' => 'a@example.com', 'code' => '12ab', 'app' => 'customer']), 400, 'validation_failed');
    expect($error['fields'])->toHaveKey('code');
});

it('uses the app ability helper for every client app', function () {
    expect(ClientApp::Vendor->ability())->toBe('app:vendor')
        ->and(ClientApp::Admin->tokenLifetimeMinutes())->toBe(720)
        ->and(ClientApp::Customer->displayName())->toBe('WeGoDeliva');
});
