<?php

use App\Enums\ClientApp;
use App\Enums\RoleName;
use App\Models\Device;

it('registers a push token for the app the token belongs to', function () {
    $rider = makeUser(RoleName::Rider);

    $this->withToken(tokenFor($rider, ClientApp::Rider))
        ->postJson('/api/v1/devices', ['fcm_token' => 'fcm-abc', 'platform' => 'android'])
        ->assertCreated()
        ->assertJsonPath('data.app', 'rider')
        ->assertJsonPath('data.platform', 'android')
        ->assertJsonMissingPath('data.fcm_token');

    expect(Device::query()->sole()->user_id)->toBe($rider->id);
});

it('moves a push token to the account now using the phone', function () {
    $first = makeUser();
    $second = makeUser();

    $this->withToken(tokenFor($first, ClientApp::Customer))->postJson('/api/v1/devices', ['fcm_token' => 'shared', 'platform' => 'ios'])->assertCreated();
    $this->app['auth']->forgetGuards();
    $this->withToken(tokenFor($second, ClientApp::Customer))->postJson('/api/v1/devices', ['fcm_token' => 'shared', 'platform' => 'ios'])->assertOk();

    expect(Device::query()->sole()->user_id)->toBe($second->id);
});

it('unregisters own devices', function () {
    $user = makeUser();
    $device = $user->devices()->create(['app' => 'customer', 'platform' => 'android', 'fcm_token' => 'mine']);

    $this->withToken(tokenFor($user, ClientApp::Customer))->deleteJson("/api/v1/devices/{$device->uuid}")->assertNoContent();

    expect(Device::query()->count())->toBe(0);
});

it("answers 404 for another user's device and leaves it alone", function () {
    $owner = makeUser();
    $device = $owner->devices()->create(['app' => 'customer', 'platform' => 'android', 'fcm_token' => 'theirs']);
    $intruder = makeUser();

    expectApiError($this->withToken(tokenFor($intruder, ClientApp::Customer))->deleteJson("/api/v1/devices/{$device->uuid}"), 404, 'not_found');

    expect(Device::query()->count())->toBe(1);
});

it('validates the platform', function () {
    $error = expectApiError($this->withToken(tokenFor(makeUser(), ClientApp::Customer))
        ->postJson('/api/v1/devices', ['fcm_token' => 'x', 'platform' => 'windows']), 400, 'validation_failed');

    expect($error['fields'])->toHaveKey('platform');
});

it('requires authentication', function () {
    expectApiError($this->postJson('/api/v1/devices', ['fcm_token' => 'x', 'platform' => 'ios']), 401, 'unauthenticated');
});
