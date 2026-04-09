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
    | Social Platform OAuth Credentials
    |--------------------------------------------------------------------------
    */

    'social' => [
        'facebook' => [
            'client_id' => env('FACEBOOK_CLIENT_ID'),
            'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        ],
        'instagram' => [
            'client_id' => env('FACEBOOK_CLIENT_ID'),
            'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        ],
        'twitter' => [
            'client_id' => env('TWITTER_CLIENT_ID'),
            'client_secret' => env('TWITTER_CLIENT_SECRET'),
        ],
        'youtube' => [
            'client_id' => env('YOUTUBE_CLIENT_ID'),
            'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        ],
        'tiktok' => [
            'client_id' => env('TIKTOK_CLIENT_ID'),
            'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        ],
        'linkedin' => [
            'client_id' => env('LINKEDIN_CLIENT_ID'),
            'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        ],
    ],

    'tidemark_ai' => [
        'url' => env('TIDEMARK_AI_URL', 'http://localhost:8001'),
    ],

    'tidemark' => [
        'webhook_secret' => env('TIDEMARK_WEBHOOK_SECRET'),
    ],

];
