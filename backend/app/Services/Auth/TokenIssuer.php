<?php

namespace App\Services\Auth;

use App\Enums\ClientApp;
use App\Enums\RoleName;
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
        $this->grantCustomerRoleToWorkers($user, $app);
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

    /**
     * Owner decision 2026-11-01: riders and vendor owners/staff may also order as customers, so
     * the customer app gives them the customer role on their first sign-in there. Admin-only
     * accounts are not upgraded; they keep separate personal accounts.
     */
    private function grantCustomerRoleToWorkers(User $user, ClientApp $app): void
    {
        if ($app !== ClientApp::Customer || $user->hasRole(RoleName::Customer)) {
            return;
        }
        if ($user->hasRole(RoleName::Rider, RoleName::VendorOwner, RoleName::VendorStaff)) {
            $user->assignRole(RoleName::Customer);
        }
    }
}
