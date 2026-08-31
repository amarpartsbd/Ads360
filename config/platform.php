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
    'default_country' => env('PLATFORM_DEFAULT_COUNTRY', 'BD'),

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
     * Finance settings (spec §83). Amounts are integer minor units so no
     * monetary value is ever configured as a float.
     */
    'finance' => [
        'minimum_deposit_minor' => (int) env('FINANCE_MINIMUM_DEPOSIT_MINOR', 100000),

        /*
         * Maker-checker thresholds (spec §25). An action at or above the
         * threshold needs a second, different person to approve it before it
         * takes effect. Zero would mean "always", which is why the default is
         * a real amount rather than a flag.
         */
        'maker_checker' => [
            'wallet_adjustment_minor' => (int) env('FINANCE_MAKER_CHECKER_ADJUSTMENT_MINOR', 5000000),
            'refund_minor' => (int) env('FINANCE_MAKER_CHECKER_REFUND_MINOR', 5000000),

            /*
             * Campaign budgets above this need a second approver (spec §25).
             * Approving a campaign holds the client's money and commits an ad
             * account to it, so past a certain size one person should not be
             * able to do it alone.
             */
            'campaign_budget_minor' => (int) env('FINANCE_MAKER_CHECKER_CAMPAIGN_MINOR', 10000000),
        ],

        // How long an unfunded payment intent stays open before it is expired.
        'payment_intent_ttl_minutes' => (int) env('FINANCE_PAYMENT_INTENT_TTL', 60),

        // Invoice numbering.
        'invoice_prefix' => env('FINANCE_INVOICE_PREFIX', 'INV'),
        'invoice_due_days' => (int) env('FINANCE_INVOICE_DUE_DAYS', 14),
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
     * The AI assistant (spec §45–§47).
     *
     * `none` by default, and deliberately so. An assistant that was on unless
     * someone turned it off would be writing copy for clients' audiences on a
     * platform where nobody had chosen a model.
     *
     * `mock` is for development only — it refuses to instantiate in production,
     * because a convincing fake is exactly what would reach a client unnoticed.
     */
    'assistant' => [
        'driver' => env('ASSISTANT_DRIVER', 'none'),

        // Languages the platform offers to ask for (spec §46). An adapter that
        // cannot write one of them says so rather than answering in another.
        'languages' => ['en', 'bn'],
    ],

    /*
     * Reporting and exports (spec §39).
     */
    'reporting' => [
        /*
         * How long a generated file stays on disk. An export is a snapshot of
         * a client's spend and conversions; it should not outlive the reason
         * it was made.
         */
        'export_lifetime_days' => (int) env('REPORT_EXPORT_LIFETIME_DAYS', 7),

        // The widest window a single export may cover, so one request cannot
        // ask for a decade of daily rows.
        'max_export_days' => (int) env('REPORT_MAX_EXPORT_DAYS', 400),
    ],

    /*
     * Advertising provider adapters. `mock` keeps development and the test
     * suite entirely free of live provider credentials (spec §95).
     */
    'advertising' => [
        'driver' => env('ADVERTISING_DRIVER', 'mock'),

        /*
         * When a managed ad account stops looking healthy (spec §20).
         *
         * Utilisation is measured against the account's own daily limit, so
         * these are percentages rather than amounts — an account's ceiling is
         * its own business, and a fixed figure would mean something different
         * on every account.
         */
        'health' => [
            'utilisation_warning_percent' => (int) env('AD_ACCOUNT_UTILISATION_WARNING', 80),
            'utilisation_critical_percent' => (int) env('AD_ACCOUNT_UTILISATION_CRITICAL', 95),

            // Consecutive sync failures before an account is treated as ill
            // rather than as having had one bad request.
            'failures_before_degraded' => (int) env('AD_ACCOUNT_FAILURES_DEGRADED', 2),
            'failures_before_critical' => (int) env('AD_ACCOUNT_FAILURES_CRITICAL', 5),

            // How long an account may go unchecked before its health is
            // treated as unknown rather than as still good.
            'stale_after_hours' => (int) env('AD_ACCOUNT_STALE_AFTER_HOURS', 6),
        ],

        /*
         * Spend reconciliation (spec §78).
         *
         * A small variance between what a provider reports and what the ledger
         * captured is normal: providers restate, and the last sync before a
         * check may be minutes old. The tolerance is what separates that from
         * a discrepancy worth a person's time.
         *
         * Expressed both as an absolute floor and as a share of spend, and a
         * variance has to clear both to be raised — a hundred taka on a
         * hundred-thousand-taka campaign is noise, and on a two-hundred-taka
         * campaign it is not.
         */
        'reconciliation' => [
            'tolerance_minor' => (int) env('RECONCILIATION_TOLERANCE_MINOR', 10000),
            'tolerance_percent' => (int) env('RECONCILIATION_TOLERANCE_PERCENT', 2),
        ],
    ],
];
