<?php

namespace App\Services\Auth;

use App\Enums\ClientApp;
use App\Exceptions\ApiException;
use App\Http\Resources\UserResource;
use App\Models\User;

/**
 * Checks whether a user may sign in to an app and issues a Sanctum token scoped to that app.
 */
final class TokenIssuer
{
    public function assertCanSignIn(User $user, ClientApp $app): void
    {
        if (! $user->status->canSignIn()) {
            throw new ApiException('account_suspended', 403);
        }
        if (! $user->canUseApp($app)) {
            throw new ApiException('role_not_allowed', 403);
        }
    }

    /**
     * @return array{token: string, token_type: string, expires_at: string, app: string, user: UserResource}
     */
    public function issue(User $user, ClientApp $app, ?string $deviceName = null): array
    {
        $this->assertCanSignIn($user, $app);

        $expiresAt = now()->addMinutes($app->tokenLifetimeMinutes());
        $name = $app->value.':'.mb_substr($deviceName ?? 'unknown device', 0, 100);
        $token = $user->createToken($name, [$app->ability()], $expiresAt);

        $user->forceFill(['last_seen_at' => now()])->save();

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'app' => $app->value,
            'user' => new UserResource($user->load('roles')),
        ];
    }
}
