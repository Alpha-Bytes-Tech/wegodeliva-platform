<?php

use App\Enums\ClientApp;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/** A user with the given roles (verified email, password "password123"). */
function makeUser(RoleName ...$roles): User
{
    return User::factory()->withRole(...($roles ?: [RoleName::Customer]))->create();
}

/** A bearer token for the user, scoped to one app. */
function tokenFor(User $user, ClientApp $app): string
{
    return $user->createToken('test', [$app->ability()])->plainTextToken;
}

/** Asserts the spec 14 §14.1 error envelope and returns the decoded error. */
function expectApiError(TestResponse $response, int $status, string $code): array
{
    $response->assertStatus($status)
        ->assertJsonStructure(['error' => ['code', 'message', 'fields']])
        ->assertJsonPath('error.code', $code);

    return $response->json('error');
}
