<?php

/*
 * WeGoDeliva platform settings that live in code/env (secrets never live here).
 * Defaults follow docs/spec/13-settings.md "Authentication".
 */
return [

    'otp' => [
        'length' => 6,
        'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 10),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'resend_seconds' => (int) env('OTP_RESEND_SECONDS', 60),
        'per_email_per_hour' => (int) env('OTP_PER_EMAIL_PER_HOUR', 5),
        'per_ip_per_hour' => (int) env('OTP_PER_IP_PER_HOUR', 20),
    ],

    'rate_limits' => [
        // Password login: per email+IP and per IP, per minute.
        'login_per_email_per_minute' => (int) env('LOGIN_PER_EMAIL_PER_MINUTE', 5),
        'login_per_ip_per_minute' => (int) env('LOGIN_PER_IP_PER_MINUTE', 30),
        // OTP verification and password reset: per IP per hour.
        'verify_per_ip_per_hour' => (int) env('OTP_VERIFY_PER_IP_PER_HOUR', 60),
        'register_per_ip_per_hour' => (int) env('REGISTER_PER_IP_PER_HOUR', 10),
    ],

    'tokens' => [
        // Mobile apps keep users signed in; admin sessions are short.
        'app_lifetime_minutes' => (int) env('APP_TOKEN_LIFETIME_MINUTES', 60 * 24 * 60),
        'admin_lifetime_minutes' => (int) env('ADMIN_TOKEN_LIFETIME_MINUTES', 60 * 12),
    ],

    'social' => [
        // Comma-separated OAuth client IDs accepted as the `aud` of ID tokens.
        'google_client_ids' => array_values(array_filter(explode(',', (string) env('GOOGLE_CLIENT_IDS', '')))),
        // Apple: the iOS bundle ID(s) and the Services ID used on Android/web.
        'apple_client_ids' => array_values(array_filter(explode(',', (string) env('APPLE_CLIENT_IDS', '')))),
    ],

    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'phone' => env('SUPER_ADMIN_PHONE'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

];
