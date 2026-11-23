<?php

/*
 * WeGoDeliva platform settings that live in code/env (secrets never live here).
 * Defaults follow docs/spec/13-settings.md "Authentication".
 */
return [

    // Vendor opening hours and order times are read in this timezone.
    'timezone' => env('PLATFORM_TIMEZONE', 'Africa/Lagos'),

    'catalogue' => [
        // How far from the customer vendors are listed (delivery or pickup), in metres.
        'browse_radius_m' => (int) env('CATALOGUE_BROWSE_RADIUS_M', 15000),
        // A point counts as being in a city when within this distance of its centre.
        'city_radius_m' => (int) env('CATALOGUE_CITY_RADIUS_M', 40000),
        'max_page_size' => 50,
        // Product image limit per product (spec 13 "Ordering").
        'max_product_images' => (int) env('MAX_PRODUCT_IMAGES', 5),
        'max_addresses' => 20,
    ],

    // Pricing defaults (spec 05, spec 13 "Fees and commission"). Rates are basis points.
    'pricing' => [
        'vat_rate_bp' => (int) env('VAT_RATE_BP', 750),
        // VAT WeGoDeliva charges on its own commission.
        'commission_vat_rate_bp' => (int) env('COMMISSION_VAT_RATE_BP', 750),
        'vendor_commission_bp' => (int) env('VENDOR_COMMISSION_BP', 500),
        'delivery_commission_bp' => (int) env('DELIVERY_COMMISSION_BP', 300),
        // Straight-line distance × this factor stands in for road distance (owner decision 2026-11-20).
        'road_factor_permille' => (int) env('ROAD_FACTOR_PERMILLE', 1300),
        // Used when a vendor follows zone fees but the drop-off is in none of its zones.
        'default_base_delivery_fee_kobo' => (int) env('DEFAULT_BASE_DELIVERY_FEE_KOBO', 50000),
        'default_per_km_fee_kobo' => (int) env('DEFAULT_PER_KM_FEE_KOBO', 10000),
        // Hard ceiling on delivery distance whatever the vendor's own radius.
        'max_delivery_distance_m' => (int) env('MAX_DELIVERY_DISTANCE_M', 20000),
        // Average rider speed for delivery time estimates (spec 05 §5.10).
        'average_speed_kmh' => (int) env('AVERAGE_RIDER_SPEED_KMH', 20),
        'max_tip_kobo' => (int) env('MAX_TIP_KOBO', 500000),
        'campus_mode' => (bool) env('FEATURE_CAMPUS_MODE', true),
    ],

    // Closed-loop wallet (spec 06 §6.4). CBN-style KYC tiers; confirm the figures against the
    // CBN circular before launch. A null max balance means no cap.
    'wallet' => [
        'min_topup_kobo' => (int) env('WALLET_MIN_TOPUP_KOBO', 10000),
        'tiers' => [
            1 => ['daily_topup_kobo' => (int) env('WALLET_TIER1_DAILY_KOBO', 5000000), 'max_balance_kobo' => (int) env('WALLET_TIER1_MAX_KOBO', 30000000)],
            2 => ['daily_topup_kobo' => (int) env('WALLET_TIER2_DAILY_KOBO', 20000000), 'max_balance_kobo' => (int) env('WALLET_TIER2_MAX_KOBO', 50000000)],
            3 => ['daily_topup_kobo' => (int) env('WALLET_TIER3_DAILY_KOBO', 500000000), 'max_balance_kobo' => null],
        ],
    ],

    // Checkout and order rules (spec 04, spec 13 "Ordering").
    'orders' => [
        'quote_ttl_minutes' => (int) env('QUOTE_TTL_MINUTES', 10),
        'max_lines' => 50,
        'max_quantity' => 50,
        'min_subtotal_kobo' => (int) env('ORDER_MIN_SUBTOTAL_KOBO', 0),
        // null = no platform maximum.
        'max_subtotal_kobo' => env('ORDER_MAX_SUBTOTAL_KOBO') === null ? null : (int) env('ORDER_MAX_SUBTOTAL_KOBO'),
        'min_schedule_lead_minutes' => (int) env('MIN_SCHEDULE_LEAD_MINUTES', 120),
        'max_schedule_days' => (int) env('MAX_SCHEDULE_DAYS', 3),
        // Scheduled orders reach the vendor at: scheduled time − prep time − this buffer.
        'schedule_release_buffer_minutes' => (int) env('SCHEDULE_RELEASE_BUFFER_MINUTES', 10),
        'customer_cancellable' => ['awaiting_payment', 'awaiting_review', 'scheduled', 'placed'],
        // Background checks (spec 04 §4.6).
        'vendor_alert_minutes' => (int) env('VENDOR_ALERT_MINUTES', 5),
        'vendor_timeout_minutes' => (int) env('VENDOR_TIMEOUT_MINUTES', 10),
        'rider_alert_minutes' => (int) env('NO_RIDER_ALERT_MINUTES', 10),
        'rider_escalate_minutes' => (int) env('NO_RIDER_ESCALATE_MINUTES', 30),
        'stale_hours' => (int) env('STALE_ORDER_HOURS', 24),
    ],

    'payments' => [
        // Gateways are switched on in milestone 2.5, once payments can be started and verified.
        'paystack' => (bool) env('PAYMENT_PAYSTACK_ENABLED', false),
        'flutterwave' => (bool) env('PAYMENT_FLUTTERWAVE_ENABLED', false),
        'wallet' => (bool) env('PAYMENT_WALLET_ENABLED', true),
        'pay_on_delivery' => (bool) env('PAYMENT_POD_ENABLED', true),
        'pay_on_delivery_max_kobo' => (int) env('POD_MAX_ORDER_KOBO', 2000000),
        // Where the gateway sends the customer after checkout; an App Link opens the app.
        'callback_url' => env('PAYMENT_CALLBACK_URL', 'https://wegodeliva.com/payment/return'),
        // Unpaid gateway orders and pending top-ups are re-verified, then given up, after this.
        'timeout_minutes' => (int) env('PAYMENT_TIMEOUT_MINUTES', 15),
        'topups_enabled' => (bool) env('WALLET_TOPUP_ENABLED', true),
    ],

    'otp' => [
        'length' => 6,
        'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 10),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'resend_seconds' => (int) env('OTP_RESEND_SECONDS', 60),
        // Sends per email address from one IP, per hour. Keyed by email + IP so a stranger
        // cannot use up someone else's allowance.
        'per_email_per_hour' => (int) env('OTP_PER_EMAIL_PER_HOUR', 5),
        // Sends per email address from all IPs together, per hour: stops mail-bombing.
        'per_email_global_per_hour' => (int) env('OTP_PER_EMAIL_GLOBAL_PER_HOUR', 20),
        'per_ip_per_hour' => (int) env('OTP_PER_IP_PER_HOUR', 20),
    ],

    'rate_limits' => [
        // Password login: per email+IP and per IP, per minute.
        'login_per_email_per_minute' => (int) env('LOGIN_PER_EMAIL_PER_MINUTE', 5),
        'login_per_ip_per_minute' => (int) env('LOGIN_PER_IP_PER_MINUTE', 30),
        // Password login per account from all IPs together, so guessing cannot be spread
        // across many addresses.
        'login_per_account_max_attempts' => (int) env('LOGIN_PER_ACCOUNT_MAX_ATTEMPTS', 10),
        'login_per_account_decay_minutes' => (int) env('LOGIN_PER_ACCOUNT_DECAY_MINUTES', 15),
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
