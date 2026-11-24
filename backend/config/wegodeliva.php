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
        'customer_cancellable' => ['awaiting_payment', 'awaiting_review', 'scheduled', 'placed', 'requested', 'confirmed'],
        // Background checks (spec 04 §4.6).
        'vendor_alert_minutes' => (int) env('VENDOR_ALERT_MINUTES', 5),
        'vendor_timeout_minutes' => (int) env('VENDOR_TIMEOUT_MINUTES', 10),
        'rider_alert_minutes' => (int) env('NO_RIDER_ALERT_MINUTES', 10),
        'rider_escalate_minutes' => (int) env('NO_RIDER_ESCALATE_MINUTES', 30),
        'stale_hours' => (int) env('STALE_ORDER_HOURS', 24),
    ],

    // What the apps read at start-up (spec 09 "Splash", spec 13 "Support").
    'app' => [
        'support' => [
            'whatsapp' => env('SUPPORT_WHATSAPP'),
            'email' => env('SUPPORT_EMAIL', 'support@wegodeliva.com'),
            'phone' => env('SUPPORT_PHONE'),
        ],
        // Versions below the minimum must update; below the latest are invited to.
        'versions' => [
            'customer' => ['min' => env('MIN_VERSION_CUSTOMER', '1.0.0'), 'latest' => env('LATEST_VERSION_CUSTOMER', '1.0.0')],
            'vendor' => ['min' => env('MIN_VERSION_VENDOR', '1.0.0'), 'latest' => env('LATEST_VERSION_VENDOR', '1.0.0')],
            'rider' => ['min' => env('MIN_VERSION_RIDER', '1.0.0'), 'latest' => env('LATEST_VERSION_RIDER', '1.0.0')],
        ],
        'store_urls' => [
            'android' => env('PLAY_STORE_URL'),
            'ios' => env('APP_STORE_URL'),
        ],
        'support_categories' => ['order', 'payment', 'delivery', 'booking', 'parcel', 'account', 'wallet', 'other'],
        'max_ticket_photos' => 5,
    ],

    // Private Supabase Storage (CLAUDE.md §2): apps upload to signed URLs; files are read through
    // short-lived signed URLs. The service-role key lives only on the server.
    'storage' => [
        'url' => env('SUPABASE_URL'),
        'service_key' => env('SUPABASE_SERVICE_ROLE_KEY'),
        'bucket' => env('SUPABASE_UPLOADS_BUCKET', 'uploads'),
        'read_ttl_seconds' => (int) env('STORAGE_READ_TTL_SECONDS', 3600),
        // What each app may upload, and the types allowed (spec 13 "Uploads").
        'kinds' => [
            'avatar' => ['folder' => 'avatars', 'types' => ['image/jpeg', 'image/png', 'image/webp']],
            'support_photo' => ['folder' => 'support', 'types' => ['image/jpeg', 'image/png', 'image/webp']],
            'prescription' => ['folder' => 'prescriptions', 'types' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']],
        ],
    ],

    // Parcels (spec 05 §5.3, spec 13 feature flags "parcels" and "multi-stop parcels").
    'parcels' => [
        'enabled' => (bool) env('PARCELS_ENABLED', true),
        'multi_stop' => (bool) env('PARCELS_MULTI_STOP', true),
        // Pickup plus drop-offs.
        'max_stops' => (int) env('PARCELS_MAX_STOPS', 5),
    ],

    // Service bookings (spec 04 §4.8, spec 13 "Services and chat").
    'bookings' => [
        'enabled' => (bool) env('BOOKINGS_ENABLED', true),
        'min_lead_minutes' => (int) env('BOOKING_MIN_LEAD_MINUTES', 60),
        'max_advance_days' => (int) env('BOOKING_MAX_ADVANCE_DAYS', 30),
        // Start times offered by the slots endpoint.
        'slot_step_minutes' => (int) env('BOOKING_SLOT_STEP_MINUTES', 30),
        // Length of a fixed-price service with no duration set.
        'default_duration_minutes' => 60,
        'free_cancel_hours' => (int) env('BOOKING_FREE_CANCEL_HOURS', 12),
        // Late-cancellation fee: 50% of the service price (owner decision 2026-11-23).
        'late_cancel_fee_bp' => (int) env('BOOKING_LATE_CANCEL_FEE_BP', 5000),
        'accept_timeout_minutes' => (int) env('BOOKING_ACCEPT_TIMEOUT_MINUTES', 120),
        'short_notice_hours' => (int) env('BOOKING_SHORT_NOTICE_HOURS', 3),
        'short_notice_accept_minutes' => (int) env('BOOKING_SHORT_NOTICE_ACCEPT_MINUTES', 30),
        // The provider can start (with the customer's code) this long before the slot.
        'start_early_minutes' => (int) env('BOOKING_START_EARLY_MINUTES', 60),
        // The customer confirms or reports a problem within this window; then it auto-confirms.
        'auto_confirm_hours' => (int) env('BOOKING_AUTO_CONFIRM_HOURS', 24),
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

    // Vendor and rider withdrawals (spec 06 §6.7, spec 13). Transfer fees are borne by the platform.
    'payouts' => [
        'enabled' => (bool) env('PAYOUTS_ENABLED', true),
        'gateway' => env('PAYOUT_GATEWAY', 'paystack'),
        'min_kobo' => (int) env('PAYOUT_MIN_KOBO', 100000),
    ],

    // Referrals (spec 06 §6.8): 5% of the first purchase, capped, funded by WeGoDeliva.
    'referrals' => [
        'enabled' => (bool) env('REFERRALS_ENABLED', true),
        'reward_bp' => (int) env('REFERRAL_REWARD_BP', 500),
        'max_reward_kobo' => (int) env('REFERRAL_MAX_REWARD_KOBO', 500000),
        'clawback_days' => (int) env('REFERRAL_CLAWBACK_DAYS', 7),
        'share_url' => env('REFERRAL_SHARE_URL', 'https://wegodeliva.com/r/'),
    ],

    // Reviews (spec 03 §3.8): customers rate a delivered order within this many days.
    'reviews' => [
        'window_days' => (int) env('REVIEW_WINDOW_DAYS', 30),
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
