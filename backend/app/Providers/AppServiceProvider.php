<?php

namespace App\Providers;

use App\Enums\RoleName;
use App\Enums\VendorMemberRole;
use App\Listeners\NotificationRouter;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Pricing\PricingSettings;
use App\Services\Social\JwksSocialTokenVerifier;
use App\Services\Social\SocialTokenVerifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SocialTokenVerifier::class, JwksSocialTokenVerifier::class);
        $this->app->bind(PricingSettings::class, fn () => PricingSettings::fromConfig());
    }

    public function boot(): void
    {
        Event::subscribe(NotificationRouter::class);

        // Catch lazy loading and silently discarded attributes during development and tests.
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->configureRateLimits();

        Gate::define('view-audit-log', fn (User $user) => $user->hasRole(RoleName::Admin, RoleName::SuperAdmin));

        // Vendor members manage their own store's catalogue, hours and status; only owners change
        // business settings and staff (spec 02 §2.2). Admin tools use 'manage-vendors'.
        Gate::define('work-at-vendor', fn (User $user, Vendor $vendor) => $vendor->memberRole($user) !== null);
        Gate::define('own-vendor', fn (User $user, Vendor $vendor) => $vendor->memberRole($user) === VendorMemberRole::Owner);
        Gate::define('manage-vendors', fn (User $user) => $user->hasRole(RoleName::Admin, RoleName::SuperAdmin));
        // Coupons: admins write all (spec 02 §2.2); zone admins do not.
        // Wallets, earnings and adjustments (spec 02 §2.2: admins only, not zone admins).
        Gate::define('manage-money', fn (User $user) => $user->hasRole(RoleName::Admin, RoleName::SuperAdmin));
        // Live order operations. Zone admins get zone-scoped access with the admin panel (Phase 6).
        Gate::define('manage-orders', fn (User $user) => $user->hasRole(RoleName::Admin, RoleName::SuperAdmin));
        Gate::define('manage-promotions', fn (User $user) => $user->hasRole(RoleName::Admin, RoleName::SuperAdmin));
    }

    /** Rate limits from spec 13 "Authentication" and spec 14 §14.1 (read per request). */
    private function configureRateLimits(): void
    {
        $email = fn (Request $request): string => mb_strtolower(trim((string) $request->input('email')));
        $cfg = fn (string $key): int => (int) config("wegodeliva.{$key}");

        // The per-email allowance is keyed by email + IP, so a stranger cannot use up someone
        // else's sends; a higher email-wide ceiling still stops mail-bombing an address.
        RateLimiter::for('otp-send', fn (Request $request) => [
            Limit::perHour($cfg('otp.per_email_per_hour'))->by('email-ip:'.$email($request).'|'.$request->ip()),
            Limit::perHour($cfg('otp.per_email_global_per_hour'))->by('email:'.$email($request)),
            Limit::perHour($cfg('otp.per_ip_per_hour'))->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('otp-verify', fn (Request $request) => [
            Limit::perHour($cfg('rate_limits.verify_per_ip_per_hour'))->by('ip:'.$request->ip()),
        ]);

        // Applies to /auth/login and /admin/auth/login. The account-wide limit stops password
        // guessing spread across many IPs; OTP sign-in still works while it is in force.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute($cfg('rate_limits.login_per_email_per_minute'))->by('email-ip:'.$email($request).'|'.$request->ip()),
            Limit::perMinutes($cfg('rate_limits.login_per_account_decay_minutes'), $cfg('rate_limits.login_per_account_max_attempts'))
                ->by('account:'.$email($request)),
            Limit::perMinute($cfg('rate_limits.login_per_ip_per_minute'))->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('register', fn (Request $request) => Limit::perHour($cfg('rate_limits.register_per_ip_per_hour'))->by($request->ip()));

        RateLimiter::for('social', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('catalogue', fn (Request $request) => Limit::perMinute(120)->by('catalogue:'.$request->ip()));

        // Money-moving admin actions and wallet reads (CLAUDE.md §4 "rate-limit wallet endpoints").
        RateLimiter::for('wallet', fn (Request $request) => Limit::perMinute(30)->by('wallet:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Checkout (quote, place, cancel) per user (CLAUDE.md §4).
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(30)->by('checkout:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Handover code tries per user, on top of the three-attempt lock per order.
        RateLimiter::for('handover', fn (Request $request) => Limit::perMinute(10)->by('handover:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by('webhooks:'.$request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }
}
