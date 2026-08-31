<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Providers\Google;

use RuntimeException;
use SensitiveParameter;

/**
 * The credentials and settings the Google Ads adapter needs (spec §64, Rule 6).
 *
 * Google needs one credential more than Meta does, and it is the one people
 * forget: a **developer token**. It is issued to the platform's manager
 * account by Google, is separate from OAuth entirely, and without it every
 * call fails with an error that says nothing useful. It is sensitive in the
 * same way an app secret is — it identifies the platform to Google — so it is
 * marked as such and never logged.
 *
 * `loginCustomerId` is the manager account the platform acts through. Google
 * requires it whenever the customer being operated on is reached through a
 * manager rather than owned directly by the authenticated user, which is the
 * normal case for managed inventory (spec §17).
 *
 * As with MetaConfig: nothing is hard-coded, there is no array form and no
 * `__toString()`, so reading a credential takes a deliberate property access.
 */
final class GoogleAdsConfig
{
    /**
     * The only *advertising* scope the platform asks Google for.
     *
     * Google Ads has exactly one, and it is all-or-nothing: there is no
     * read-only variant that also permits publishing. Asking for anything
     * beyond it — Analytics, Merchant Center — would widen the blast radius of
     * a leaked token for capabilities this adapter does not implement (§27).
     *
     * `openid` and `email` accompany it and are not advertising scopes at all:
     * they grant access to no ad account and exist only so the platform knows
     * which Google account a grant belongs to. See GoogleAdsProvider::identity.
     */
    public const ADS_SCOPE = 'https://www.googleapis.com/auth/adwords';

    public function __construct(
        public readonly string $clientId,
        #[SensitiveParameter]
        public readonly string $clientSecret,
        #[SensitiveParameter]
        public readonly string $developerToken,
        public readonly string $apiVersion,
        public readonly string $apiUrl,
        public readonly string $authUrl,
        public readonly string $tokenUrl,
        public readonly string $userInfoUrl,
        public readonly string $redirectUri,
        /** @var list<string> */
        public readonly array $scopes,
        public readonly int $requestTimeout,
        public readonly int $connectTimeout,
        public readonly int $maxAttempts,
        public readonly int $retryDelayMilliseconds,
        public readonly ?string $loginCustomerId = null,
        /**
         * The platform's own grant on its own manager account.
         *
         * Most of the inventory is operated with the platform's credentials
         * rather than a client's (spec §17), and Google authenticates every
         * call. Without this, publishing to a managed account has nothing to
         * authenticate with.
         */
        #[SensitiveParameter]
        public readonly ?string $refreshToken = null,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $google */
        $google = config('services.google_ads', []);

        return new self(
            clientId: (string) ($google['client_id'] ?? ''),
            clientSecret: (string) ($google['client_secret'] ?? ''),
            developerToken: (string) ($google['developer_token'] ?? ''),
            apiVersion: (string) ($google['api_version'] ?? 'v21'),
            apiUrl: (string) ($google['api_url'] ?? 'https://googleads.googleapis.com'),
            authUrl: (string) ($google['auth_url'] ?? 'https://accounts.google.com/o/oauth2/v2/auth'),
            tokenUrl: (string) ($google['token_url'] ?? 'https://oauth2.googleapis.com/token'),
            userInfoUrl: (string) ($google['user_info_url'] ?? 'https://openidconnect.googleapis.com/v1/userinfo'),
            redirectUri: (string) ($google['redirect_uri'] ?? ''),
            scopes: array_values(array_filter((array) ($google['scopes'] ?? [self::ADS_SCOPE]))),
            requestTimeout: (int) ($google['request_timeout'] ?? 60),
            connectTimeout: (int) ($google['connect_timeout'] ?? 10),
            maxAttempts: (int) ($google['max_attempts'] ?? 3),
            retryDelayMilliseconds: (int) ($google['retry_delay_ms'] ?? 500),
            loginCustomerId: self::digits($google['login_customer_id'] ?? null),
            refreshToken: ($google['refresh_token'] ?? null) ?: null,
        );
    }

    /** Whether the adapter has enough to talk to Google at all. */
    public function isConfigured(): bool
    {
        return $this->clientId !== ''
            && $this->clientSecret !== ''
            && $this->developerToken !== ''
            && $this->redirectUri !== '';
    }

    /**
     * @throws RuntimeException naming what is missing, so the fix is obvious
     */
    public function assertUsable(): void
    {
        $missing = [];

        if ($this->clientId === '') {
            $missing[] = 'GOOGLE_ADS_CLIENT_ID';
        }

        if ($this->clientSecret === '') {
            $missing[] = 'GOOGLE_ADS_CLIENT_SECRET';
        }

        if ($this->developerToken === '') {
            $missing[] = 'GOOGLE_ADS_DEVELOPER_TOKEN';
        }

        if ($this->redirectUri === '') {
            $missing[] = 'GOOGLE_ADS_REDIRECT_URI';
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'The Google Ads adapter is missing configuration: '.implode(', ', $missing).'. '
                .'Set them, or run with ADVERTISING_DRIVER=mock.'
            );
        }
    }

    /**
     * Google customer ids are written with dashes for people (123-456-7890)
     * and without them for the API (1234567890). Both forms arrive here —
     * from a client pasting one out of the Google Ads interface, from a
     * discovered asset, from a stored ad account — and sending the wrong one
     * is an error Google reports as a missing customer rather than a malformed
     * id, which is a confusing hour for whoever debugs it.
     */
    public static function digits(mixed $customerId): ?string
    {
        if (! is_string($customerId) && ! is_int($customerId)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $customerId) ?? '';

        return $digits === '' ? null : $digits;
    }

    /**
     * A description safe for a log or a health screen: says whether the
     * adapter is configured without saying what it is configured with.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'api_version' => $this->apiVersion,
            'configured' => $this->isConfigured(),
            'has_developer_token' => $this->developerToken !== '',
            'has_platform_grant' => $this->refreshToken !== null,
            'login_customer_id' => $this->loginCustomerId,
            'scopes' => $this->scopes,
        ];
    }
}
