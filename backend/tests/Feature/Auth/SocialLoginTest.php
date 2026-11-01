<?php

use App\Enums\RoleName;
use App\Enums\SocialProvider;
use App\Exceptions\ApiException;
use App\Models\AuthIdentity;
use App\Models\User;
use App\Services\Social\SocialIdentity;
use App\Services\Social\SocialTokenVerifier;

/** Test double: maps fake ID tokens to identities. */
function fakeSocial(array $tokens): void
{
    app()->instance(SocialTokenVerifier::class, new class($tokens) implements SocialTokenVerifier
    {
        public function __construct(private array $tokens) {}

        public function verify(SocialProvider $provider, string $idToken): SocialIdentity
        {
            $data = $this->tokens[$idToken] ?? throw new ApiException('social_token_invalid', 401);

            return new SocialIdentity($provider, $data['sub'], $data['email'] ?? null, $data['verified'] ?? true, $data['name'] ?? null);
        }
    });
}

beforeEach(fn () => fakeSocial([
    'new' => ['sub' => 'g-1', 'email' => 'Ngozi@Gmail.com', 'name' => 'Ngozi Okafor'],
    'unverified' => ['sub' => 'g-2', 'email' => 'x@example.com', 'verified' => false],
    'no-email' => ['sub' => 'a-3', 'email' => null],
    'apple-no-name' => ['sub' => 'a-4', 'email' => 'relay@privaterelay.appleid.com'],
]));

it('asks for a phone number on first sign-in, then creates a verified customer', function () {
    expectApiError($this->postJson('/api/v1/auth/social/google', ['id_token' => 'new']), 422, 'phone_required');
    expect(User::query()->count())->toBe(0);

    $this->postJson('/api/v1/auth/social/google', ['id_token' => 'new', 'phone' => '08091234567'])
        ->assertCreated()
        ->assertJsonPath('data.is_new_user', true)
        ->assertJsonPath('data.app', 'customer')
        ->assertJsonPath('data.user.email', 'ngozi@gmail.com')
        ->assertJsonPath('data.user.email_verified', true)
        ->assertJsonPath('data.user.roles', ['customer']);

    $user = User::query()->sole();
    expect($user->phone)->toBe('+2348091234567')
        ->and($user->password)->toBeNull()
        ->and($user->authIdentities()->sole()->provider_user_id)->toBe('g-1');
});

it('signs a linked user straight in on later visits', function () {
    $this->postJson('/api/v1/auth/social/google', ['id_token' => 'new', 'phone' => '08091234567'])->assertCreated();

    $this->postJson('/api/v1/auth/social/google', ['id_token' => 'new'])
        ->assertOk()
        ->assertJsonPath('data.is_new_user', false);

    expect(User::query()->count())->toBe(1);
});

it('links to an existing account with the same verified email', function () {
    $existing = User::factory()->unverified()->withRole(RoleName::Customer)->create(['email' => 'ngozi@gmail.com']);

    $this->postJson('/api/v1/auth/social/google', ['id_token' => 'new'])
        ->assertOk()
        ->assertJsonPath('data.user.uuid', $existing->uuid);

    expect(AuthIdentity::query()->sole()->user_id)->toBe($existing->id)
        ->and($existing->fresh()->email_verified_at)->not->toBeNull();
});

it('does not sign a rider-only account in to the customer app via social login', function () {
    makeUser(RoleName::Rider)->forceFill(['email' => 'ngozi@gmail.com'])->save();

    expectApiError($this->postJson('/api/v1/auth/social/google', ['id_token' => 'new']), 403, 'role_not_allowed');
    expect(AuthIdentity::query()->count())->toBe(0);
});

it('rejects tokens the verifier does not accept', function () {
    expectApiError($this->postJson('/api/v1/auth/social/apple', ['id_token' => 'forged']), 401, 'social_token_invalid');
});

it('requires a verified email from the provider', function (string $token) {
    expectApiError($this->postJson('/api/v1/auth/social/google', ['id_token' => $token, 'phone' => '08091234567']), 422, 'social_email_unverified');
})->with(['unverified', 'no-email']);

it('needs a name when the provider does not send one', function () {
    $error = expectApiError($this->postJson('/api/v1/auth/social/apple', ['id_token' => 'apple-no-name', 'phone' => '08091234567']), 400, 'validation_failed');
    expect($error['fields'])->toHaveKey('name');

    $this->postJson('/api/v1/auth/social/apple', ['id_token' => 'apple-no-name', 'phone' => '08091234567', 'name' => 'Ada Obi'])
        ->assertCreated()
        ->assertJsonPath('data.user.name', 'Ada Obi');
});

it('rejects unknown providers', function () {
    $this->postJson('/api/v1/auth/social/facebook', ['id_token' => 'new'])->assertNotFound();
});

it('rejects a phone number that is already used', function () {
    makeUser()->forceFill(['phone' => '+2348091234567'])->save();

    $error = expectApiError($this->postJson('/api/v1/auth/social/google', ['id_token' => 'new', 'phone' => '08091234567']), 400, 'validation_failed');
    expect($error['fields'])->toHaveKey('phone');
});
