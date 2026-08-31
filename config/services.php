<?php

declare(strict_types=1);

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

    /*
     * Meta (Facebook and Instagram) Marketing API, spec §26.
     *
     * The app secret is a credential and lives only in the environment. The
     * adapter refuses to build without the three required values rather than
     * failing later against Meta with an error nobody can interpret.
     */
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),

        /*
         * Pinned deliberately. Meta deprecates a version roughly every two
         * years and changes field shapes between them; an unpinned client
         * would start failing on a date nobody chose.
         */
        'api_version' => env('META_API_VERSION', 'v21.0'),

        'graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com'),
        'dialog_url' => env('META_DIALOG_URL', 'https://www.facebook.com'),
        'redirect_uri' => env('META_REDIRECT_URI'),

        /*
         * Only what the platform actually uses. Asking for more than that is
         * both a slower app review and a larger blast radius if a token leaks
         * (spec §27).
         */
        'scopes' => [
            'ads_management',
            'ads_read',
            'business_management',
            'pages_show_list',
            'pages_read_engagement',
            'instagram_basic',
        ],

        'request_timeout' => (int) env('META_REQUEST_TIMEOUT', 30),
        'connect_timeout' => (int) env('META_CONNECT_TIMEOUT', 10),
        'max_attempts' => (int) env('META_MAX_ATTEMPTS', 3),
        'retry_delay_ms' => (int) env('META_RETRY_DELAY_MS', 500),

        // Echoed back during the webhook subscription handshake (spec §52).
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),

        'business_id' => env('META_BUSINESS_ID'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
