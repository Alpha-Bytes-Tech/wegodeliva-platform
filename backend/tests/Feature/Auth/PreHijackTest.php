<?php

use App\Enums\ClientApp;
use App\Enums\RoleName;
use App\Enums\SocialProvider;
use App\Mail\OtpCodeMail;
use App\Models\AuthIdentity;
use App\Models\User;
use App\Services\Social\SocialIdentity;
use App\Services\Social\SocialTokenVerifier;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/*
 * ADR 0012: someone who registers another person's email before that person does must not keep
 * access once the real owner proves the address.
 */

beforeEach(fn () => Mail::fake());

/** The attacker signs up with the victim's email, their own phone and password P. */
function attackerRegisters(): User
{
    test()->postJson('/api/v1/auth/register', [
        'name' => 'Not Ngozi', 'email' => 'ngozi@gmail.com', 'phone' => '08031112222',
        'password' => 'attacker123', 'accept_terms' => true,
    ])->assertCreated();

    return User::query()->where('email', 'ngozi@gmail.com')->sole();
}

/** The latest code emailed to an address. */
function latestCode(string $email): string
{
    $code = '';
    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use ($email, &$code) {
        if ($mail->hasTo($email)) {
            $code = $mail->code;
        }

        return true;
    });

    return $code;
}

function loginWith(string $password): TestResponse
{
    return test()->postJson('/api/v1/auth/login', ['email' => 'ngozi@gmail.com', 'password' => $password, 'app' => 'customer']);
}

it('drops the sign-up password and older tokens when the real owner verifies the email by code', function () {
    $user = attackerRegisters();
    // Whatever token the attacker might hold from before verification.
    $attackerToken = tokenFor($user, ClientApp::Customer);
    $code = latestCode('ngozi@gmail.com');

    $this->postJson('/api/v1/auth/otp/verify', ['email' => 'ngozi@gmail.com', 'code' => $code, 'app' => 'customer'])
        ->assertOk()
        ->assertJsonPath('data.user.email_verified', true)
        ->assertJsonPath('data.user.phone_confirmation_required', true)
        ->assertJsonPath('data.user.has_password', false);

    $user->refresh();
    expect($user->password)->toBeNull()
        ->and($user->phone_confirmation_required)->toBeTrue()
        ->and($user->tokens()->count())->toBe(1); // only the one just issued to the verifier

    expectApiError(loginWith('attacker123'), 401, 'invalid_credentials');
    $this->app['auth']->forgetGuards();
    expectApiError($this->withToken($attackerToken)->getJson('/api/v1/auth/me'), 401, 'unauthenticated');
});

it('does not touch an already verified account on later code sign-ins', function () {
    $user = makeUser();
    $this->postJson('/api/v1/auth/otp/send', ['email' => $user->email, 'app' => 'customer'])->assertStatus(202);
    $other = tokenFor($user, ClientApp::Customer);

    $this->postJson('/api/v1/auth/otp/verify', ['email' => $user->email, 'code' => latestCode($user->email), 'app' => 'customer'])
        ->assertOk()
        ->assertJsonPath('data.user.phone_confirmation_required', false);

    expect($user->fresh()->password)->not->toBeNull()
        ->and($user->tokens()->count())->toBe(2);
    $this->withToken($other)->getJson('/api/v1/auth/me')->assertOk();
});

it('applies the same reset when the email is first proven with a password-reset code', function () {
    $user = attackerRegisters();
    $attackerToken = tokenFor($user, ClientApp::Customer);
    Mail::fake();
    $this->postJson('/api/v1/auth/otp/send', ['email' => 'ngozi@gmail.com', 'app' => 'customer', 'purpose' => 'reset'])->assertStatus(202);

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'ngozi@gmail.com', 'code' => latestCode('ngozi@gmail.com'),
        'password' => 'victim1234', 'password_confirmation' => 'victim1234',
    ])->assertOk();

    expect($user->fresh()->phone_confirmation_required)->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
    expectApiError(loginWith('attacker123'), 401, 'invalid_credentials');
    loginWith('victim1234')->assertOk();
    $this->app['auth']->forgetGuards();
    expectApiError($this->withToken($attackerToken)->getJson('/api/v1/auth/me'), 401, 'unauthenticated');
});

it('lets the owner re-confirm their phone number once', function () {
    $user = attackerRegisters();
    $this->postJson('/api/v1/auth/otp/verify', ['email' => 'ngozi@gmail.com', 'code' => latestCode('ngozi@gmail.com'), 'app' => 'customer'])->assertOk();
    $token = $user->tokens()->sole();
    $plain = $user->createToken('owner', $token->abilities)->plainTextToken;

    $this->withToken($plain)->postJson('/api/v1/auth/phone/confirm', ['phone' => '0809 999 8888'])
        ->assertOk()
        ->assertJsonPath('data.phone', '+2348099998888')
        ->assertJsonPath('data.phone_confirmation_required', false);

    expectApiError($this->withToken($plain)->postJson('/api/v1/auth/phone/confirm', ['phone' => '08099998888']), 409, 'phone_already_confirmed');
});

it('lets the owner keep the same phone number but not take someone else\'s', function () {
    $user = attackerRegisters();
    $user->forceFill(['phone_confirmation_required' => true])->save();
    $someoneElse = makeUser();
    $token = tokenFor($user, ClientApp::Customer);

    $error = expectApiError($this->withToken($token)->postJson('/api/v1/auth/phone/confirm', ['phone' => $someoneElse->phone]), 400, 'validation_failed');
    expect($error['fields'])->toHaveKey('phone');

    $this->withToken($token)->postJson('/api/v1/auth/phone/confirm', ['phone' => '08031112222'])->assertOk();
});

describe('social sign-in', function () {
    beforeEach(fn () => app()->instance(SocialTokenVerifier::class, new class implements SocialTokenVerifier
    {
        public function verify(SocialProvider $provider, string $idToken): SocialIdentity
        {
            return new SocialIdentity($provider, 'g-victim', 'ngozi@gmail.com', true, 'Ngozi');
        }
    }));

    it('resets an unverified account before linking it', function () {
        $user = attackerRegisters();
        $attackerToken = tokenFor($user, ClientApp::Customer);

        $this->postJson('/api/v1/auth/social/google', ['id_token' => 'victim'])
            ->assertOk()
            ->assertJsonPath('data.user.uuid', $user->uuid)
            ->assertJsonPath('data.user.phone_confirmation_required', true);

        $user->refresh();
        expect($user->password)->toBeNull()
            ->and($user->email_verified_at)->not->toBeNull()
            ->and(AuthIdentity::query()->sole()->user_id)->toBe($user->id)
            ->and($user->tokens()->count())->toBe(1);
        expectApiError(loginWith('attacker123'), 401, 'invalid_credentials');
        $this->app['auth']->forgetGuards();
        expectApiError($this->withToken($attackerToken)->getJson('/api/v1/auth/me'), 401, 'unauthenticated');
    });

    it('links a verified account without resetting it', function () {
        $user = makeUser(RoleName::Customer);
        $user->forceFill(['email' => 'ngozi@gmail.com'])->save();
        $existingToken = tokenFor($user, ClientApp::Customer);

        $this->postJson('/api/v1/auth/social/google', ['id_token' => 'victim'])
            ->assertOk()
            ->assertJsonPath('data.user.phone_confirmation_required', false);

        expect($user->fresh()->password)->not->toBeNull()
            ->and($user->tokens()->count())->toBe(2);
        loginWith('password123')->assertOk();
        $this->withToken($existingToken)->getJson('/api/v1/auth/me')->assertOk();
    });
});
