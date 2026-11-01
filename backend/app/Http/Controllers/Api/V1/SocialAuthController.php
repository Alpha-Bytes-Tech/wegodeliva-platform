<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientApp;
use App\Enums\RoleName;
use App\Enums\SocialProvider;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SocialLoginRequest;
use App\Models\AuthIdentity;
use App\Models\User;
use App\Services\Auth\TokenIssuer;
use App\Services\Social\SocialIdentity;
use App\Services\Social\SocialTokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Google / Apple sign-in for the customer app (spec 01 §1.4, spec 02 §2.4). The ID token is
 * verified server-side; the client never tells us who the user is.
 */
class SocialAuthController extends Controller
{
    public function __construct(
        private readonly SocialTokenVerifier $verifier,
        private readonly TokenIssuer $tokens,
    ) {}

    public function __invoke(SocialLoginRequest $request, SocialProvider $provider): JsonResponse
    {
        $identity = $this->verifier->verify($provider, $request->string('id_token')->toString());
        $deviceName = $request->input('device_name');

        $linked = AuthIdentity::query()
            ->where('provider', $provider->value)
            ->where('provider_user_id', $identity->subject)
            ->first();
        if ($linked !== null) {
            return $this->respond($linked->user, $deviceName, false);
        }

        if ($identity->email === null || ! $identity->emailVerified) {
            throw new ApiException('social_email_unverified', 422);
        }
        $email = User::normaliseEmail($identity->email);

        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null) {
            // The provider vouches for the email, so the person controls it: link the accounts.
            $this->tokens->assertCanSignIn($existing, ClientApp::Customer);
            $this->link($existing, $identity, $email);
            $existing->email_verified_at ??= now();
            $existing->save();

            return $this->respond($existing, $deviceName, false);
        }

        $phone = $request->input('phone');
        if (! is_string($phone) || $phone === '') {
            throw new ApiException('phone_required', 422, fields: ['phone' => [__('errors.phone_required')]]);
        }
        $name = $request->input('name') ?? $identity->name;
        if (! is_string($name) || trim($name) === '') {
            throw new ApiException('validation_failed', 400, fields: ['name' => ['Please enter your name.']]);
        }

        $user = DB::transaction(function () use ($identity, $email, $phone, $name): User {
            $user = new User(['name' => trim($name), 'email' => $email, 'phone' => $phone]);
            $user->email_verified_at = now();
            $user->save();
            $user->assignRole(RoleName::Customer);
            $this->link($user, $identity, $email);

            return $user;
        });

        return $this->respond($user, $deviceName, true, 201);
    }

    private function link(User $user, SocialIdentity $identity, string $email): void
    {
        $user->authIdentities()->updateOrCreate(
            ['provider' => $identity->provider->value],
            ['provider_user_id' => $identity->subject, 'email' => $email],
        );
    }

    private function respond(User $user, mixed $deviceName, bool $isNew, int $status = 200): JsonResponse
    {
        $result = $this->tokens->issue($user, ClientApp::Customer, is_string($deviceName) ? $deviceName : null);

        return response()->json(['data' => $result + ['is_new_user' => $isNew]], $status);
    }
}
