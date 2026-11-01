<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientApp;
use App\Enums\OtpPurpose;
use App\Enums\RoleName;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminLoginRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\OtpService;
use App\Services\Auth\TokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Authentication (spec 02 §2.4, spec 14 §14.2 "Auth", ADR 0008).
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly TokenIssuer $tokens,
    ) {}

    /** Customer sign-up. The role is always `customer`; the email is verified by OTP next. */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data): User {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => $data['password'] ?? null,
            ]);
            if (! empty($data['referral_code'])) {
                $user->referred_by_user_id = User::query()->where('referral_code', $data['referral_code'])->value('id');
            }
            $user->save();
            $user->assignRole(RoleName::Customer);

            return $user;
        });

        $this->otp->send($user->email, OtpPurpose::Login, ClientApp::Customer, $request->ip());

        return response()->json(['data' => [
            'user' => ['uuid' => $user->uuid, 'email' => $user->email],
            'otp' => $this->otpTiming(),
        ]], 201);
    }

    /** Sends a sign-in or password-reset code. Same answer whether or not the account exists. */
    public function sendOtp(SendOtpRequest $request): JsonResponse
    {
        $this->otp->send($request->email(), $request->purpose(), $request->app(), $request->ip());

        return response()->json(['data' => ['otp' => $this->otpTiming()]], 202);
    }

    /** Verifies a sign-in code: signs the user in and marks the email as verified. */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $this->otp->verify($request->email(), OtpPurpose::Login, $request->string('code')->toString());

        $user = User::query()->where('email', $request->email())->first()
            ?? throw new ApiException('otp_invalid', 422);

        $this->tokens->assertCanSignIn($user, $request->app());
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return response()->json(['data' => $this->tokens->issue($user, $request->app(), $request->input('device_name'))]);
    }

    /** Email + password sign-in from a mobile app. */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->checkPassword($request->email(), $request->string('password')->toString());

        if (! $user->hasVerifiedEmail()) {
            throw new ApiException('email_not_verified', 403);
        }

        return response()->json(['data' => $this->tokens->issue($user, $request->app(), $request->input('device_name'))]);
    }

    /**
     * Admin panel sign-in. TODO(before launch): TOTP second factor (spec 02 §2.4); the response
     * will then be `totp_required` until a valid code is supplied.
     */
    public function adminLogin(AdminLoginRequest $request, AuditLogger $audit): JsonResponse
    {
        try {
            $user = $this->checkPassword($request->email(), $request->string('password')->toString());
            $this->tokens->assertCanSignIn($user, ClientApp::Admin);
        } catch (ApiException $e) {
            $audit->record(null, 'admin.login_failed', after: ['email' => $request->email(), 'reason' => $e->errorCode]);
            throw $e;
        }

        $result = $this->tokens->issue($user, ClientApp::Admin, 'admin web');
        $audit->record($user, 'admin.login', 'user', $user->uuid);

        return response()->json(['data' => $result]);
    }

    /** Resets a password with an emailed code and signs out every session. */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->otp->verify($request->email(), OtpPurpose::Reset, $request->string('code')->toString());

        $user = User::query()->where('email', $request->email())->first()
            ?? throw new ApiException('otp_invalid', 422);

        DB::transaction(function () use ($user, $request): void {
            $user->password = $request->string('password')->toString();
            $user->email_verified_at ??= now();
            $user->save();
            $user->tokens()->delete();
        });

        return response()->json(['data' => ['password_reset' => true]]);
    }

    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user->load('roles'));
    }

    public function logout(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function checkPassword(string $email, string $password): User
    {
        $user = User::query()->where('email', $email)->first();

        // Hash something even when the user does not exist, so timing does not reveal accounts.
        $hash = $user->password ?? '$2y$12$XUi1DOkRwxu1sH8GgI9rfuFWyLbSEGUaL4RmoVcOaLqxbimj.Iow2';
        if (! Hash::check($password, $hash) || $user === null || $user->password === null) {
            throw new ApiException('invalid_credentials', 401);
        }

        return $user;
    }

    /** @return array{expires_in: int, resend_in: int} */
    private function otpTiming(): array
    {
        return [
            'expires_in' => (int) config('wegodeliva.otp.ttl_minutes') * 60,
            'resend_in' => (int) config('wegodeliva.otp.resend_seconds'),
        ];
    }
}
