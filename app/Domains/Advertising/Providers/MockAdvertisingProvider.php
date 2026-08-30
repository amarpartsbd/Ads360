<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Providers;

use App\Domains\Advertising\Contracts\AdvertisingProvider;
use App\Domains\Advertising\DTOs\AuthorizationRequest;
use App\Domains\Advertising\DTOs\DiscoveredAsset;
use App\Domains\Advertising\DTOs\ProviderAccountState;
use App\Domains\Advertising\DTOs\ProviderCredentials;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\ProviderCapability;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Integration\Models\ProviderConnection;
use DateTimeImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A provider stand-in for development and tests (spec §95).
 *
 * The whole flow — authorise, exchange, discover, verify, sync health — can be
 * exercised without a live app review or merchant approval, which is what makes
 * it possible to build and test the campaign engine before provider access
 * comes through.
 *
 * It refuses to instantiate in production. A mock that silently reports success
 * against a live environment would let campaigns appear to publish when nothing
 * had been sent anywhere.
 */
abstract class MockAdvertisingProvider implements AdvertisingProvider
{
    /** Failures the test has asked for, keyed by the method they apply to. */
    private array $failures = [];

    /** @var list<DiscoveredAsset>|null */
    private ?array $assetOverride = null;

    private ?ProviderAccountState $accountStateOverride = null;

    public function __construct()
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'The mock advertising providers must never run in production. '
                .'Set ADVERTISING_DRIVER to a live driver.'
            );
        }
    }

    abstract public function provider(): Provider;

    /**
     * @return list<DiscoveredAsset>
     */
    abstract protected function defaultAssets(): array;

    public function supports(ProviderCapability $capability): bool
    {
        // The mock deliberately reports the same capability set a live adapter
        // would, so callers exercise their §87 fallbacks rather than skipping
        // them in development and discovering them in production.
        return $capability !== ProviderCapability::Webhooks;
    }

    public function authorizationRequest(string $state, array $scopes = []): AuthorizationRequest
    {
        return new AuthorizationRequest(
            // Points back into the application: the developer completes the
            // round trip without leaving the machine.
            url: route('client.assets.oauth.simulate', [
                'provider' => Str::lower($this->provider()->value),
                'state' => $state,
            ]),
            state: $state,
            scopes: $scopes,
        );
    }

    public function exchangeCode(string $code): ProviderCredentials
    {
        $this->failIfAsked(__FUNCTION__);

        return new ProviderCredentials(
            accessToken: 'mock-access-'.Str::random(40),
            externalUserId: 'mock-user-'.substr(sha1($code), 0, 12),
            refreshToken: 'mock-refresh-'.Str::random(40),
            expiresAt: new DateTimeImmutable('+60 days'),
            scopes: ['ads_read', 'ads_management'],
            accountName: $this->provider()->label().' Sandbox Account',
        );
    }

    public function refreshCredentials(ProviderConnection $connection): ProviderCredentials
    {
        $this->failIfAsked(__FUNCTION__);

        if (! $connection->hasRefreshToken()) {
            throw ProviderUnavailable::authenticationFailed(
                $this->provider(),
                'the connection has no refresh token',
            );
        }

        return new ProviderCredentials(
            accessToken: 'mock-access-'.Str::random(40),
            externalUserId: $connection->external_user_id,
            refreshToken: $connection->refreshToken(),
            expiresAt: new DateTimeImmutable('+60 days'),
            scopes: $connection->scopes,
            accountName: $connection->account_name,
        );
    }

    public function verifyConnection(ProviderConnection $connection): bool
    {
        if (isset($this->failures[__FUNCTION__])) {
            return false;
        }

        return $connection->revoked_at === null;
    }

    public function discoverAssets(ProviderConnection $connection): array
    {
        $this->failIfAsked(__FUNCTION__);

        return $this->assetOverride ?? $this->defaultAssets();
    }

    public function accountState(string $externalAccountId, ?ProviderConnection $connection = null): ProviderAccountState
    {
        $this->failIfAsked(__FUNCTION__);

        if ($this->accountStateOverride !== null) {
            return $this->accountStateOverride;
        }

        return new ProviderAccountState(
            externalAccountId: $externalAccountId,
            status: 'ACTIVE',
            billingStatus: 'OK',
            spentTodayMinor: 0,
            spentThisMonthMinor: 0,
            currency: 'USD',
            raw: ['mock' => true],
        );
    }

    // ------------------------------------------------------------------
    // Test hooks
    // ------------------------------------------------------------------

    /**
     * Make a method throw, so callers' error handling is exercised rather than
     * assumed.
     */
    public function willFail(string $method, ProviderUnavailable $exception): void
    {
        $this->failures[$method] = $exception;
    }

    /** Make verifyConnection() report the grant as gone. */
    public function willReportRevoked(): void
    {
        $this->failures['verifyConnection'] = true;
    }

    /**
     * @param  list<DiscoveredAsset>  $assets
     */
    public function willDiscover(array $assets): void
    {
        $this->assetOverride = $assets;
    }

    /** Make the provider report a particular state for every account. */
    public function willReportAccountState(ProviderAccountState $state): void
    {
        $this->accountStateOverride = $state;
    }

    private function failIfAsked(string $method): void
    {
        $failure = $this->failures[$method] ?? null;

        if ($failure instanceof ProviderUnavailable) {
            throw $failure;
        }
    }
}
