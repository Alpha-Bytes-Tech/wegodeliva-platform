<?php

use App\Enums\ClientApp;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Mail\OtpCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('returns the signed-in profile', function () {
    $user = makeUser();

    $this->withToken(tokenFor($user, ClientApp::Customer))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.uuid', $user->uuid)
        ->assertJsonPath('data.phone', $user->phone)
        ->assertJsonPath('data.roles', ['customer'])
        ->assertJsonMissingPath('data.id');
});

it('logs out by revoking only the current token', function () {
    $user = makeUser();
    $current = tokenFor($user, ClientApp::Customer);
    tokenFor($user, ClientApp::Customer);

    $this->withToken($current)->postJson('/api/v1/auth/logout')->assertNoContent();

    expect($user->tokens()->count())->toBe(1);
    $this->app['auth']->forgetGuards();
    expectApiError($this->withToken($current)->getJson('/api/v1/auth/me'), 401, 'unauthenticated');
});

it('rejects expired tokens', function () {
    $user = makeUser();
    $token = $user->createToken('t', ['app:customer'], now()->addMinute())->plainTextToken;

    $this->travel(2)->minutes();

    expectApiError($this->withToken($token)->getJson('/api/v1/auth/me'), 401, 'unauthenticated');
});

it('blocks suspended users who still hold a token', function () {
    $user = makeUser();
    $token = tokenFor($user, ClientApp::Customer);
    $user->forceFill(['status' => UserStatus::Suspended])->save();

    expectApiError($this->withToken($token)->getJson('/api/v1/auth/me'), 403, 'account_suspended');
});

it('resets a password with an emailed code and signs out every session', function () {
    Mail::fake();
    $user = makeUser(RoleName::Customer);
    tokenFor($user, ClientApp::Customer);

    $this->postJson('/api/v1/auth/otp/send', ['email' => $user->email, 'app' => 'customer', 'purpose' => 'reset'])->assertStatus(202);
    $code = '';
    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code) {
        $code = $mail->code;
        $mail->assertSeeInText('reset your password');

        return true;
    });

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => $user->email, 'code' => $code, 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
    ])->assertOk()->assertJsonPath('data.password_reset', true);

    expect($user->tokens()->count())->toBe(0);
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'newpass123', 'app' => 'customer'])->assertOk();
});

it('does not accept a sign-in code for a password reset', function () {
    Mail::fake();
    $user = makeUser();
    $this->postJson('/api/v1/auth/otp/send', ['email' => $user->email, 'app' => 'customer'])->assertStatus(202);
    $code = '';
    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    expectApiError($this->postJson('/api/v1/auth/password/reset', [
        'email' => $user->email, 'code' => $code, 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
    ]), 422, 'otp_invalid');
});

it('validates the new password', function () {
    $error = expectApiError($this->postJson('/api/v1/auth/password/reset', [
        'email' => 'a@example.com', 'code' => '123456', 'password' => 'newpass123', 'password_confirmation' => 'different1',
    ]), 400, 'validation_failed');

    expect($error['fields'])->toHaveKey('password');
});

it('stores emails in lower case', function () {
    $user = User::factory()->create(['email' => '  MiXeD@Example.COM ']);

    expect($user->email)->toBe('mixed@example.com');
});
