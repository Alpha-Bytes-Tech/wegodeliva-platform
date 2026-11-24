<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientApp;
use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Rules\NigerianMobile;
use App\Services\Auth\AccountDeletion;
use App\Services\Auth\OtpService;
use App\Support\PhoneNumber;
use App\Support\UploadPaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The signed-in user's own account, for every app (spec 09 "Edit profile / change password",
 * "Delete account"): profile, password, a change of email confirmed by a code sent to the new
 * address, and deleting the account confirmed by a code.
 */
class AccountController extends Controller
{
    public function __construct(private readonly OtpService $otp, private readonly AccountDeletion $deletion) {}

    public function update(Request $request): UserResource
    {
        $user = $this->user($request);
        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => PhoneNumber::normalise($request->input('phone')) ?? $request->input('phone')]);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'phone' => ['sometimes', 'string', new NigerianMobile, Rule::unique('users', 'phone')->ignore($user->id)],
            'avatar_path' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:'.UploadPaths::rule('avatar', $user)],
            'current_password' => ['nullable', 'string', 'max:72'],
        ]);

        // A new phone number needs the password, when the account has one (spec 09).
        if (isset($data['phone']) && $data['phone'] !== $user->phone && $user->password !== null) {
            $this->checkPassword($user, $data['current_password'] ?? null);
        }

        $user->forceFill(array_intersect_key($data, array_flip(['name', 'phone', 'avatar_path'])))->save();

        return new UserResource($user);
    }

    /** Sets or changes the password and signs out every other session. */
    public function password(Request $request): UserResource
    {
        $user = $this->user($request);
        $data = $request->validate([
            'current_password' => [$user->password === null ? 'nullable' : 'required', 'string', 'max:72'],
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        if ($user->password !== null) {
            $this->checkPassword($user, $data['current_password']);
        }

        DB::transaction(function () use ($user, $data): void {
            $user->password = $data['password'];
            $user->save();
            $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();
        });

        return new UserResource($user);
    }

    public function requestEmailChange(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $email = User::normaliseEmail((string) $request->validate(['email' => ['required', 'email:rfc', 'max:191']])['email']);
        if ($email === $user->email) {
            throw new ApiException('validation_failed', 400, ['email' => ['This is already your email address.']]);
        }
        if (User::withTrashed()->where('email', $email)->exists()) {
            throw new ApiException('email_taken', 409, ['email' => ['Another account uses this email address.']]);
        }

        $user->forceFill(['pending_email' => $email])->save();
        $this->otp->send($email, OtpPurpose::Verify, $this->app($request), $request->ip(), requireAccount: false);

        return response()->json(['data' => ['pending_email' => $email]], 202);
    }

    public function confirmEmailChange(Request $request): UserResource
    {
        $user = $this->user($request);
        $code = (string) $request->validate(['code' => ['required', 'string', 'size:'.config('wegodeliva.otp.length')]])['code'];
        if ($user->pending_email === null) {
            throw new ApiException('otp_invalid', 422);
        }

        $this->otp->verify($user->pending_email, OtpPurpose::Verify, $code);
        if (User::withTrashed()->where('email', $user->pending_email)->whereKeyNot($user->id)->exists()) {
            throw new ApiException('email_taken', 409, ['email' => ['Another account uses this email address.']]);
        }
        $user->forceFill(['email' => $user->pending_email, 'pending_email' => null, 'email_verified_at' => now()])->save();

        return new UserResource($user);
    }

    /** Sends the code that confirms deletion, after checking nothing blocks it. */
    public function requestDeletion(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->assertDeletable($user);
        $this->otp->send($user->email, OtpPurpose::DeleteAccount, $this->app($request), $request->ip());

        return response()->json(['data' => ['sent_to' => $user->email]], 202);
    }

    public function destroy(Request $request): Response
    {
        $user = $this->user($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'size:'.config('wegodeliva.otp.length')],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $this->assertDeletable($user);
        $this->otp->verify($user->email, OtpPurpose::DeleteAccount, $data['code']);
        $this->deletion->delete($user, $data['reason'] ?? null);

        return response()->noContent();
    }

    private function assertDeletable(User $user): void
    {
        $blockers = $this->deletion->blockers($user);
        if ($blockers !== []) {
            throw new ApiException('account_delete_blocked', 409, meta: ['reasons' => $blockers]);
        }
    }

    private function checkPassword(User $user, ?string $password): void
    {
        if ($password === null || ! Hash::check($password, (string) $user->password)) {
            throw new ApiException('password_incorrect', 422, ['current_password' => ['That password is not correct.']]);
        }
    }

    private function app(Request $request): ClientApp
    {
        foreach (ClientApp::cases() as $app) {
            if ($this->user($request)->tokenCan($app->ability())) {
                return $app;
            }
        }

        return ClientApp::Customer;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
