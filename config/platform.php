<?php

declare(strict_types=1);

/**
 * Platform-wide settings (spec §83).
 *
 * Everything here is configuration, not code: branding, limits, security rules
 * and feature flags are read from this file so no value is hard-coded in the
 * application and tenants can override what applies to them.
 */
return [

    'name' => env('PLATFORM_NAME', 'Ads360'),
    'support_email' => env('PLATFORM_SUPPORT_EMAIL', 'support@ads360.test'),
    'default_currency' => env('PLATFORM_DEFAULT_CURRENCY', 'BDT'),
    'default_timezone' => env('PLATFORM_DEFAULT_TIMEZONE', 'Asia/Dhaka'),

    'security' => [
        // Administrators must hold a confirmed authenticator (spec §9).
        'admin_requires_two_factor' => (bool) env('ADMIN_REQUIRE_TWO_FACTOR', true),

        // Shorter idle window for the administration area, in minutes.
        'admin_session_lifetime' => (int) env('ADMIN_SESSION_LIFETIME', 30),

        // Comma-separated CIDR ranges or addresses. Empty means no restriction.
        'admin_ip_allowlist' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('ADMIN_IP_ALLOWLIST', '')))
        )),

        'max_login_attempts' => (int) env('AUTH_MAX_LOGIN_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 15),

        // Step-up authentication window for privileged actions, in seconds.
        'step_up_timeout' => (int) env('AUTH_STEP_UP_TIMEOUT', 900),

        'content_security_policy' => (bool) env('CONTENT_SECURITY_POLICY', ! env('APP_DEBUG', false)),

        'force_https' => (bool) env('FORCE_HTTPS', false),
    ],

    /*
     * Feature flags for staged rollout (spec §84). Modules from later phases
     * stay dark until their flag is turned on.
     */
    'features' => [
        'google_ads' => (bool) env('FEATURE_GOOGLE_ADS', false),
        'agency_module' => (bool) env('FEATURE_AGENCY_MODULE', false),
        'ai_assistant' => (bool) env('FEATURE_AI_ASSISTANT', false),
        'white_label' => (bool) env('FEATURE_WHITE_LABEL', false),
        'automated_rules' => (bool) env('FEATURE_AUTOMATED_RULES', false),
        'advanced_reporting' => (bool) env('FEATURE_ADVANCED_REPORTING', false),
    ],

    /*
     * Advertising provider adapters. `mock` keeps development and the test
     * suite entirely free of live provider credentials (spec §95).
     */
    'advertising' => [
        'driver' => env('ADVERTISING_DRIVER', 'mock'),
    ],
];
