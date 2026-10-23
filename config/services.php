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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mock Insurer Integration Configuration
    |--------------------------------------------------------------------------
    */
    'mock_insurer' => [
        'base_url' => env('MOCK_INSURER_BASE_URL', 'http://127.0.0.1:8000/api/mock-insurer'),
        'timeout' => (int) env('MOCK_INSURER_TIMEOUT_SECONDS', 3),
        'stale_minutes' => (int) env('PENDING_STALE_MINUTES', 10),
        'signature_secret' => env('MOCK_SIGNATURE_SECRET', 'mock-shared-secret-placeholder'),
    ],

];
