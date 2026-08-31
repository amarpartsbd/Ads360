<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Providers\Meta;

use RuntimeException;
use SensitiveParameter;

/**
 * The credentials and settings the Meta adapter needs (spec §64, Rule 6).
 *
 * Everything comes from configuration; nothing is hard-coded. The app secret
 * is marked sensitive so it is redacted from stack traces, and this object has
 * no array form or `__toString()` — reading it takes a deliberate property
 * access, which makes accidental logging hard.
 *
 * `assertUsable()` exists so a missing credential fails at the point the
 * adapter is built, with a message an operator can act on, rather than as a
 * confusing 400 from Meta on the first client's first campaign.
 */
final class MetaConfig
{
    public function __construct(
        public readonly string $appId,
        #[SensitiveParameter]
        public readonly string $appSecret,
        public readonly string $apiVersion,
        public readonly string $graphUrl,
        public readonly string $dialogUrl,
        public readonly string $redirectUri,
        /** @var list<string> */
        public readonly array $scopes,
        public readonly int $requestTimeout,
        public readonly int $connectTimeout,
        public readonly int $maxAttempts,
        public readonly int $retryDelayMilliseconds,
        #[SensitiveParameter]
        public readonly ?string $webhookVerifyToken = null,
        public readonly ?string $businessId = null,
        /**
         * The platform's own grant on its own ad accounts.
         *
         * A managed ad account (spec §17) has no client connection behind it —
         * the platform owns it and lends it out — and Meta authenticates every
         * call. Without this, nothing can be published to one.
         *
         * This is a **system user** token from the platform's Business
         * Manager, not a person's. A token belonging to an employee stops
         * working the day they leave, which is the worst possible way to
         * discover the difference.
         */
        #[SensitiveParameter]
        public readonly ?string $systemUserToken = null,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $meta */
        $meta = config('services.meta', []);

        return new self(
            appId: (string) ($meta['app_id'] ?? ''),
            appSecret: (string) ($meta['app_secret'] ?? ''),
            apiVersion: (string) ($meta['api_version'] ?? 'v21.0'),
            graphUrl: (string) ($meta['graph_url'] ?? 'https://graph.facebook.com'),
            dialogUrl: (string) ($meta['dialog_url'] ?? 'https://www.facebook.com'),
            redirectUri: (string) ($meta['redirect_uri'] ?? ''),
            scopes: array_values(array_filter((array) ($meta['scopes'] ?? []))),
            requestTimeout: (int) ($meta['request_timeout'] ?? 30),
            connectTimeout: (int) ($meta['connect_timeout'] ?? 10),
            maxAttempts: (int) ($meta['max_attempts'] ?? 3),
            retryDelayMilliseconds: (int) ($meta['retry_delay_ms'] ?? 500),
            webhookVerifyToken: ($meta['webhook_verify_token'] ?? null) ?: null,
            businessId: ($meta['business_id'] ?? null) ?: null,
            systemUserToken: ($meta['system_user_token'] ?? null) ?: null,
        );
    }

    /** Whether the adapter has enough to talk to Meta at all. */
    public function isConfigured(): bool
    {
        return $this->appId !== '' && $this->appSecret !== '' && $this->redirectUri !== '';
    }

    /**
     * @throws RuntimeException naming what is missing, so the fix is obvious
     */
    public function assertUsable(): void
    {
        $missing = [];

        if ($this->appId === '') {
            $missing[] = 'META_APP_ID';
        }

        if ($this->appSecret === '') {
            $missing[] = 'META_APP_SECRET';
        }

        if ($this->redirectUri === '') {
            $missing[] = 'META_REDIRECT_URI';
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'The Meta adapter is missing configuration: '.implode(', ', $missing).'. '
                .'Set them, or run with ADVERTISING_DRIVER=mock.'
            );
        }
    }

    /**
     * Meta's proof that a call comes from the app that owns the token, rather
     * than from someone who merely stole the token. Sent alongside calls that
     * act on a user's behalf when the app requires it.
     */
    public function appSecretProof(#[SensitiveParameter] string $accessToken): string
    {
        return hash_hmac('sha256', $accessToken, $this->appSecret);
    }

    /** The app's own token, for calls that are about the app not a user. */
    public function appAccessToken(): string
    {
        return $this->appId.'|'.$this->appSecret;
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
            'has_webhook_token' => $this->webhookVerifyToken !== null,
            'has_platform_grant' => $this->systemUserToken !== null,
            'scopes' => $this->scopes,
        ];
    }
}
