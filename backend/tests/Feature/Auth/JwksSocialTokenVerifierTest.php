<?php

use App\Enums\SocialProvider;
use App\Exceptions\ApiException;
use App\Services\Social\JwksSocialTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

function b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** Generates an RSA key pair and fakes the provider's JWKS endpoint with its public key. */
function fakeJwks(string $url): OpenSSLAsymmetricKey
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $details = openssl_pkey_get_details($key);
    Http::fake([$url => Http::response(['keys' => [[
        'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'test-key',
        'n' => b64url($details['rsa']['n']), 'e' => b64url($details['rsa']['e']),
    ]]])]);

    return $key;
}

function idToken(OpenSSLAsymmetricKey $key, array $claims): string
{
    return JWT::encode($claims + ['iat' => time(), 'exp' => time() + 600], $key, 'RS256', 'test-key');
}

beforeEach(function () {
    config([
        'wegodeliva.social.google_client_ids' => ['google-client.apps.googleusercontent.com'],
        'wegodeliva.social.apple_client_ids' => ['com.wegodeliva.customerapp'],
    ]);
});

it('accepts a correctly signed Google token for our client', function () {
    $key = fakeJwks('https://www.googleapis.com/oauth2/v3/certs');
    $token = idToken($key, [
        'iss' => 'https://accounts.google.com', 'aud' => 'google-client.apps.googleusercontent.com',
        'sub' => '1234', 'email' => 'a@gmail.com', 'email_verified' => true, 'name' => 'Ada',
    ]);

    $identity = (new JwksSocialTokenVerifier)->verify(SocialProvider::Google, $token);

    expect($identity->subject)->toBe('1234')
        ->and($identity->email)->toBe('a@gmail.com')
        ->and($identity->emailVerified)->toBeTrue()
        ->and($identity->name)->toBe('Ada');
});

it('accepts Apple tokens whose email_verified is the string "true"', function () {
    $key = fakeJwks('https://appleid.apple.com/auth/keys');
    $token = idToken($key, [
        'iss' => 'https://appleid.apple.com', 'aud' => 'com.wegodeliva.customerapp',
        'sub' => 'apple-1', 'email' => 'x@privaterelay.appleid.com', 'email_verified' => 'true',
    ]);

    $identity = (new JwksSocialTokenVerifier)->verify(SocialProvider::Apple, $token);

    expect($identity->emailVerified)->toBeTrue()->and($identity->name)->toBeNull();
});

it('rejects tokens for another audience, issuer, signer or after expiry', function (array $claims, bool $foreignKey = false) {
    $key = fakeJwks('https://www.googleapis.com/oauth2/v3/certs');
    $signer = $foreignKey ? openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]) : $key;
    $token = JWT::encode($claims + [
        'iss' => 'https://accounts.google.com', 'aud' => 'google-client.apps.googleusercontent.com',
        'sub' => '1234', 'iat' => time(), 'exp' => time() + 600,
    ], $signer, 'RS256', 'test-key');

    expect(fn () => (new JwksSocialTokenVerifier)->verify(SocialProvider::Google, $token))
        ->toThrow(ApiException::class, 'social_token_invalid');
})->with([
    'other audience' => [['aud' => 'someone-else']],
    'other issuer' => [['iss' => 'https://evil.example.com']],
    'expired' => [['exp' => time() - 3600]],
    'foreign key' => [[], true],
    'empty subject' => [['sub' => '']],
]);

it('rejects everything when no client IDs are configured', function () {
    config(['wegodeliva.social.google_client_ids' => []]);

    expect(fn () => (new JwksSocialTokenVerifier)->verify(SocialProvider::Google, 'x.y.z'))
        ->toThrow(ApiException::class, 'social_token_invalid');
});

it('rejects garbage tokens', function () {
    fakeJwks('https://www.googleapis.com/oauth2/v3/certs');

    expect(fn () => (new JwksSocialTokenVerifier)->verify(SocialProvider::Google, 'not-a-jwt'))
        ->toThrow(ApiException::class, 'social_token_invalid');
});
