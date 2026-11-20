<?php

use App\Enums\ClientApp;
use App\Enums\RoleName;
use App\Models\Address;
use Database\Seeders\CitySeeder;

beforeEach(fn () => $this->seed(CitySeeder::class));

function addressPayload(array $overrides = []): array
{
    return array_merge([
        'label' => 'Hostel',
        'line1' => 'Room 214, Moremi Hall',
        'landmark' => 'Opposite the blue gate',
        'lat' => 6.5170,
        'lng' => 3.3890,
        'contact_phone' => '0803 123 4567',
    ], $overrides);
}

it('creates addresses with the city worked out from the pin; the first one is the default', function () {
    $user = makeUser();
    $token = tokenFor($user, ClientApp::Customer);

    $this->withToken($token)->postJson('/api/v1/me/addresses', addressPayload(['city_id' => 999, 'zone_id' => 999]))
        ->assertCreated()
        ->assertJsonPath('data.city', 'Lagos')
        ->assertJsonPath('data.zone', null)
        ->assertJsonPath('data.is_default', true)
        ->assertJsonPath('data.landmark', 'Opposite the blue gate')
        ->assertJsonPath('data.contact_phone', '+2348031234567')
        ->assertJsonMissingPath('data.user_id');

    $this->withToken($token)->postJson('/api/v1/me/addresses', addressPayload(['label' => 'Home']))
        ->assertCreated()
        ->assertJsonPath('data.is_default', false);
});

it('moves the default when another address is made default', function () {
    $user = makeUser();
    $token = tokenFor($user, ClientApp::Customer);
    $first = $this->withToken($token)->postJson('/api/v1/me/addresses', addressPayload())->json('data.id');
    $second = $this->withToken($token)->postJson('/api/v1/me/addresses', addressPayload(['is_default' => true]))->json('data.id');

    expect(Address::query()->where('uuid', $first)->value('is_default'))->toBeFalse()
        ->and(Address::query()->where('uuid', $second)->value('is_default'))->toBeTrue();

    $this->withToken($token)->patchJson("/api/v1/me/addresses/{$first}", ['is_default' => true])->assertOk();
    $this->withToken($token)->getJson('/api/v1/me/addresses')
        ->assertOk()
        ->assertJsonPath('data.0.id', $first)
        ->assertJsonPath('data.0.is_default', true)
        ->assertJsonPath('data.1.is_default', false);
});

it('updates and deletes own addresses only; others answer 404', function () {
    $owner = makeUser();
    $uuid = $this->withToken(tokenFor($owner, ClientApp::Customer))->postJson('/api/v1/me/addresses', addressPayload())->json('data.id');
    $this->app['auth']->forgetGuards();
    $intruder = tokenFor(makeUser(), ClientApp::Customer);

    expectApiError($this->withToken($intruder)->patchJson("/api/v1/me/addresses/{$uuid}", ['line1' => 'Mine now']), 404, 'not_found');
    expectApiError($this->withToken($intruder)->deleteJson("/api/v1/me/addresses/{$uuid}"), 404, 'not_found');
    $this->app['auth']->forgetGuards();

    $token = tokenFor($owner, ClientApp::Customer);
    $this->withToken($token)->patchJson("/api/v1/me/addresses/{$uuid}", ['lat' => 9.0765, 'lng' => 7.3986])
        ->assertOk()
        ->assertJsonPath('data.city', null);
    $this->withToken($token)->deleteJson("/api/v1/me/addresses/{$uuid}")->assertNoContent();
    expect(Address::query()->count())->toBe(0)->and(Address::withTrashed()->count())->toBe(1);
});

it('validates addresses and caps how many a customer keeps', function () {
    $user = makeUser();
    $token = tokenFor($user, ClientApp::Customer);

    $error = expectApiError($this->withToken($token)->postJson('/api/v1/me/addresses', ['lat' => 100]), 400, 'validation_failed');
    expect($error['fields'])->toHaveKeys(['line1', 'lat', 'lng']);
    expectApiError($this->withToken($token)->postJson('/api/v1/me/addresses', addressPayload(['contact_phone' => '12345'])), 400, 'validation_failed');

    config(['wegodeliva.catalogue.max_addresses' => 1]);
    $this->withToken($token)->postJson('/api/v1/me/addresses', addressPayload())->assertCreated();
    expectApiError($this->withToken($token)->postJson('/api/v1/me/addresses', addressPayload()), 422, 'address_limit_reached');
});

it('needs a customer-app token', function () {
    $rider = makeUser(RoleName::Rider, RoleName::Customer);

    expectApiError($this->getJson('/api/v1/me/addresses'), 401, 'unauthenticated');
    expectApiError($this->withToken(tokenFor($rider, ClientApp::Rider))->getJson('/api/v1/me/addresses'), 403, 'forbidden');
});
