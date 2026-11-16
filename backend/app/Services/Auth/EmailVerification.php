<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Marks an email address as verified (ADR 0012).
 *
 * Anyone can register any email address, so until the address is proven nothing chosen at
 * sign-up is trusted. The first time the owner proves it (email code, password-reset code or a
 * provider-verified social sign-in), we drop the password, revoke every token and ask the owner
 * to re-confirm the phone number. Later verifications change nothing.
 */
final class EmailVerification
{
    /** @return bool true when this call verified the email for the first time */
    public function markVerified(User $user): bool
    {
        if ($user->email_verified_at !== null) {
            return false;
        }

        $firstTime = DB::transaction(function () use ($user): bool {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->email_verified_at !== null) {
                return false; // a concurrent request got here first
            }

            $locked->forceFill([
                'email_verified_at' => now(),
                'password' => null,
                'phone_confirmation_required' => true,
            ])->save();
            $locked->tokens()->delete();

            return true;
        });

        $user->refresh();

        return $firstTime;
    }
}
