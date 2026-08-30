<?php

declare(strict_types=1);

namespace App\Domains\Advertising\DTOs;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * Tokens returned by a provider.
 *
 * The token values are marked sensitive so they are redacted from stack traces,
 * and this object deliberately has no `__toString()` or array form: the only
 * way out of it is to read a property, which makes accidental logging hard.
 */
final readonly class ProviderCredentials
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        #[SensitiveParameter]
        public string $accessToken,
        public string $externalUserId,
        #[SensitiveParameter]
        public ?string $refreshToken = null,
        public ?DateTimeImmutable $expiresAt = null,
        public array $scopes = [],
        public ?string $accountName = null,
    ) {}

    /**
     * A representation safe to log or audit: everything about the connection
     * except the parts that grant access.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'external_user_id' => $this->externalUserId,
            'account_name' => $this->accountName,
            'scopes' => $this->scopes,
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'has_refresh_token' => $this->refreshToken !== null,
        ];
    }
}
