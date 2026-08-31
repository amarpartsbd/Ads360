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

        /*
         * The platform's own grant on its own ad accounts (spec §17).
         *
         * A system user token from the platform's Business Manager, not a
         * person's: a token belonging to an employee stops working the day
         * they leave. Managed ad accounts have no client connection behind
         * them, so without this nothing can be published to one.
         */
        'system_user_token' => env('META_SYSTEM_USER_TOKEN'),
    ],

    /*
     * Google Ads API, spec §26.
     *
     * Google needs one credential more than Meta: a developer token, issued to
     * the platform's manager account and separate from OAuth entirely. It is a
     * secret in the same sense an app secret is and lives only in the
     * environment. The adapter refuses to build without the four required
     * values rather than failing later against Google with an error nobody can
     * interpret.
     */
    'google_ads' => [
        'client_id' => env('GOOGLE_ADS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),

        /*
         * Pinned deliberately. Google publishes a new API version roughly
         * every four months and sunsets each about a year later, changing
         * field shapes between them; an unpinned client would start failing on
         * a date nobody chose.
         */
        'api_version' => env('GOOGLE_ADS_API_VERSION', 'v21'),

        'api_url' => env('GOOGLE_ADS_API_URL', 'https://googleads.googleapis.com'),
        'auth_url' => env('GOOGLE_ADS_AUTH_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
        'token_url' => env('GOOGLE_ADS_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
        'user_info_url' => env('GOOGLE_ADS_USER_INFO_URL', 'https://openidconnect.googleapis.com/v1/userinfo'),
        'redirect_uri' => env('GOOGLE_ADS_REDIRECT_URI'),

        /*
         * `adwords` is the only advertising scope Google offers, and it is
         * all-or-nothing. The other two are not advertising scopes at all:
         * they grant access to no ad account and exist only so the platform
         * knows which Google account a grant belongs to, which is what makes a
         * reconnection update the existing connection rather than create a
         * second one beside it.
         */
        'scopes' => [
            'https://www.googleapis.com/auth/adwords',
            'openid',
            'email',
        ],

        /*
         * The manager account the platform operates its own inventory through
         * (spec §17). Google requires it whenever the customer being acted on
         * is reached through a manager rather than owned by the authenticated
         * user.
         */
        'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),

        /*
         * The platform's own grant on its own manager account. Managed ad
         * accounts have no client grant behind them and Google authenticates
         * every call, so without this nothing can be published to one.
         *
         * Obtained once, by hand, by authorising the platform's own Google
         * account through the same consent flow clients use.
         */
        'refresh_token' => env('GOOGLE_ADS_REFRESH_TOKEN'),

        // Longer than Meta's: a GAQL report over a wide window genuinely takes
        // longer to come back than a Graph field list.
        'request_timeout' => (int) env('GOOGLE_ADS_REQUEST_TIMEOUT', 60),
        'connect_timeout' => (int) env('GOOGLE_ADS_CONNECT_TIMEOUT', 10),
        'max_attempts' => (int) env('GOOGLE_ADS_MAX_ATTEMPTS', 3),
        'retry_delay_ms' => (int) env('GOOGLE_ADS_RETRY_DELAY_MS', 500),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
