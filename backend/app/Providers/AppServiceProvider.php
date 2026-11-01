<?php

namespace App\Providers;

use App\Enums\RoleName;
use App\Models\User;
use App\Services\Social\JwksSocialTokenVerifier;
use App\Services\Social\SocialTokenVerifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SocialTokenVerifier::class, JwksSocialTokenVerifier::class);
    }

    public function boot(): void
    {
        // Catch lazy loading and silently discarded attributes during development and tests.
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->configureRateLimits();

        Gate::define('view-audit-log', fn (User $user) => $user->hasRole(RoleName::Admin, RoleName::SuperAdmin));
    }

    /** Rate limits from spec 13 "Authentication" and spec 14 §14.1 (read per request). */
    private function configureRateLimits(): void
    {
        $email = fn (Request $request): string => mb_strtolower(trim((string) $request->input('email')));
        $cfg = fn (string $key): int => (int) config("wegodeliva.{$key}");

        RateLimiter::for('otp-send', fn (Request $request) => [
            Limit::perHour($cfg('otp.per_email_per_hour'))->by('email:'.$email($request)),
            Limit::perHour($cfg('otp.per_ip_per_hour'))->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('otp-verify', fn (Request $request) => [
            Limit::perHour($cfg('rate_limits.verify_per_ip_per_hour'))->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute($cfg('rate_limits.login_per_email_per_minute'))->by('email:'.$email($request).'|'.$request->ip()),
            Limit::perMinute($cfg('rate_limits.login_per_ip_per_minute'))->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('register', fn (Request $request) => Limit::perHour($cfg('rate_limits.register_per_ip_per_hour'))->by($request->ip()));

        RateLimiter::for('social', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }
}
