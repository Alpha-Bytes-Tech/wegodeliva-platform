<?php

namespace App\Services\Auth;

use App\Enums\ClientApp;
use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Mail\OtpCodeMail;
use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Email one-time codes (spec 02 §2.4, ADR 0008): 6 digits, stored hashed, valid 10 minutes,
 * at most 5 attempts, one send per 60 seconds per address and purpose.
 */
final class OtpService
{
    /**
     * Sends a code if an account exists for the address. The response is the same either way,
     * so the endpoint cannot be used to discover which emails have accounts.
     */
    public function send(string $email, OtpPurpose $purpose, ClientApp $app, ?string $ip): void
    {
        $email = User::normaliseEmail($email);
        $cooldownKey = "otp-resend:{$purpose->value}:{$email}";
        $resendSeconds = (int) config('wegodeliva.otp.resend_seconds');

        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            $retryAfter = RateLimiter::availableIn($cooldownKey);
            throw new ApiException('otp_resend_too_soon', 429, meta: ['retry_after' => $retryAfter], headers: ['Retry-After' => (string) $retryAfter]);
        }
        RateLimiter::hit($cooldownKey, $resendSeconds);

        if (! User::query()->where('email', $email)->exists()) {
            return;
        }

        $code = $this->generateCode();
        $ttl = (int) config('wegodeliva.otp.ttl_minutes');

        DB::transaction(function () use ($email, $purpose, $app, $ip, $code, $ttl): void {
            // A new code replaces any older one for the same purpose.
            OtpChallenge::query()->openFor($email, $purpose)->update(['consumed_at' => now()]);

            OtpChallenge::query()->create([
                'channel' => 'email',
                'destination' => $email,
                'purpose' => $purpose,
                'app' => $app,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes($ttl),
                'ip' => $ip,
            ]);

            Mail::to($email)->send(new OtpCodeMail($code, $purpose, $app, $ttl));
        });
    }

    /**
     * Checks a code and consumes the challenge. Wrong codes count towards the attempt limit
     * even though the request fails, so the attempt counter is committed before throwing.
     */
    public function verify(string $email, OtpPurpose $purpose, string $code): OtpChallenge
    {
        $email = User::normaliseEmail($email);
        $maxAttempts = (int) config('wegodeliva.otp.max_attempts');

        /** @var array{0: string|null, 1: OtpChallenge|null, 2: int} $outcome */
        $outcome = DB::transaction(function () use ($email, $purpose, $code, $maxAttempts): array {
            $challenge = OtpChallenge::query()
                ->openFor($email, $purpose)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($challenge === null) {
                return ['otp_invalid', null, 0];
            }
            if ($challenge->isExpired()) {
                return ['otp_expired', null, 0];
            }
            if ($challenge->attempts >= $maxAttempts) {
                return ['otp_attempts_exceeded', null, 0];
            }

            if (! Hash::check($code, $challenge->code_hash)) {
                $challenge->increment('attempts');
                $remaining = $maxAttempts - $challenge->attempts;

                return [$remaining > 0 ? 'otp_invalid' : 'otp_attempts_exceeded', null, $remaining];
            }

            $challenge->forceFill(['consumed_at' => now(), 'attempts' => $challenge->attempts + 1])->save();

            return [null, $challenge, 0];
        });

        [$error, $challenge, $remaining] = $outcome;
        if ($error !== null) {
            $meta = $error === 'otp_invalid' && $remaining > 0 ? ['attempts_remaining' => $remaining] : [];
            throw new ApiException($error, 422, meta: $meta);
        }

        /** @var OtpChallenge $challenge */
        return $challenge;
    }

    private function generateCode(): string
    {
        $length = (int) config('wegodeliva.otp.length');

        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }
}
