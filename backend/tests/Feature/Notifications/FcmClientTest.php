<?php

use App\Services\Notifications\FcmClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $this->publicKey = openssl_pkey_get_details($key)['key'];
    config(['services.fcm.credentials' => base64_encode(json_encode(['project_id' => 'wegodeliva-test', 'client_email' => 'push@wegodeliva-test.iam.gserviceaccount.com', 'private_key' => $pem]))]);
    Cache::flush();
});

it('signs in with the service account and sends a message to one device', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/wegodeliva-test/messages/1']),
    ]);
    $client = new FcmClient;

    expect($client->enabled())->toBeTrue()
        ->and($client->send('device-token', 'New order AB12CD34', '2 item(s)', ['order' => 'AB12CD34'], urgent: true, channel: 'new_orders'))->toBe(FcmClient::SENT)
        ->and($client->send('device-token', 'Again', 'Body', []))->toBe(FcmClient::SENT);

    Http::assertSentCount(3); // one token request, cached for the second message
    Http::assertSent(function (Request $r) {
        if (! str_contains($r->url(), 'oauth2')) {
            return true;
        }
        [$header, $claims, $signature] = explode('.', $r['assertion']);
        $decode = fn (string $s) => base64_decode(strtr($s, '-_', '+/'));
        $payload = json_decode($decode($claims), true);

        return $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && $payload['scope'] === 'https://www.googleapis.com/auth/firebase.messaging'
            && openssl_verify("{$header}.{$claims}", $decode($signature), $this->publicKey, OPENSSL_ALGO_SHA256) === 1;
    });
    Http::assertSent(fn (Request $r) => ! str_contains($r->url(), 'fcm.googleapis.com')
        || ($r->url() === 'https://fcm.googleapis.com/v1/projects/wegodeliva-test/messages:send'
            && $r->hasHeader('Authorization', 'Bearer ya29.test')
            && ($r['message']['token'] === 'device-token')
            && ($r['message']['title'] ?? null) === null));
    Http::assertSent(fn (Request $r) => ! str_contains($r->url(), 'fcm.googleapis.com') || $r['message']['notification']['title'] !== 'New order AB12CD34'
        || ($r['message']['android'] === ['priority' => 'HIGH', 'notification' => ['channel_id' => 'new_orders', 'sound' => 'default']]
            && $r['message']['apns']['payload']['aps']['interruption-level'] === 'time-sensitive'
            && $r['message']['data'] === ['order' => 'AB12CD34']));
});

it('reports tokens FCM no longer knows, and other failures', function (int $status, array $body, string $expected) {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test']),
        'fcm.googleapis.com/*' => Http::response($body, $status),
    ]);

    expect((new FcmClient)->send('token', 'T', 'B', []))->toBe($expected);
})->with([
    'unregistered' => [404, ['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], FcmClient::INVALID_TOKEN],
    'malformed token' => [400, ['error' => ['status' => 'INVALID_ARGUMENT']], FcmClient::INVALID_TOKEN],
    'quota' => [429, ['error' => ['status' => 'RESOURCE_EXHAUSTED']], FcmClient::FAILED],
]);

it('fails softly when Google cannot be reached or refuses the sign-in', function (Closure $fake) {
    $fake();

    expect((new FcmClient)->send('token', 'T', 'B', []))->toBe(FcmClient::FAILED);
})->with([
    'no connection' => [fn () => Http::fake(fn () => throw new ConnectionException('down'))],
    'no access token' => [fn () => Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)])],
]);

it('is switched off without valid credentials', function (?string $credentials) {
    config(['services.fcm.credentials' => $credentials]);
    Http::fake();

    $client = new FcmClient;
    expect($client->enabled())->toBeFalse()->and($client->send('token', 'T', 'B', []))->toBe(FcmClient::FAILED);
    Http::assertNothingSent();
})->with([
    'missing' => [null],
    'not a service account' => [base64_encode('{"type":"something"}')],
]);

it('refuses to sign with a broken private key', function () {
    config(['services.fcm.credentials' => base64_encode(json_encode(['project_id' => 'p', 'client_email' => 'e', 'private_key' => 'not a key']))]);
    Http::fake();

    expect(@(new FcmClient)->send('token', 'T', 'B', []))->toBe(FcmClient::FAILED);
    Http::assertNothingSent();
});
