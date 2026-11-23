<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Payment gateways (spec 06). Test keys on staging, live keys only at launch.
    'paystack' => [
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
    ],

    'flutterwave' => [
        'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com'),
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        // The "secret hash" set on the Flutterwave dashboard; sent back as the verif-hash header.
        'secret_hash' => env('FLUTTERWAVE_SECRET_HASH'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
