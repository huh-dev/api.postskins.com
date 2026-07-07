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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'steam' => [
        'client_id' => null,
        'client_secret' => env('STEAM_CLIENT_SECRET'),
        'redirect' => env('STEAM_REDIRECT_URI'),
        'force_https' => env('STEAM_FORCE_HTTPS', false),
        'allowed_hosts' => array_filter(explode(',', env('STEAM_ALLOWED_HOSTS', ''))),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    ],

    'steamapis' => [
        'key' => env('STEAMAPIS_KEY'),
        'base_url' => env('STEAMAPIS_BASE_URL', 'https://api.steamapis.com/v2'),
    ],

    'steam_inventory' => [
        // Which inventory source to use: "steamapis" (proxied, production) or
        // "official" (free, rate-limited, good for local development).
        'driver' => env('STEAM_INVENTORY_DRIVER', 'steamapis'),
    ],

    // The Node "GC" service that sends P2P trade offers on a seller's behalf.
    'gc' => [
        'url' => env('GC_URL', 'http://localhost:3100'),
        'secret' => env('GC_SHARED_SECRET'),
    ],

];
