<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Providers;

use App\Domains\Advertising\Contracts\AdvertisingProvider;
use App\Domains\Advertising\DTOs\AdDraft;
use App\Domains\Advertising\DTOs\AdSetDraft;
use App\Domains\Advertising\DTOs\AuthorizationRequest;
use App\Domains\Advertising\DTOs\CampaignDraft;
use App\Domains\Advertising\DTOs\CampaignInsights;
use App\Domains\Advertising\DTOs\DailyInsightRow;
use App\Domains\Advertising\DTOs\DiscoveredAsset;
use App\Domains\Advertising\DTOs\ProviderAccountState;
use App\Domains\Advertising\DTOs\ProviderCredentials;
use App\Domains\Advertising\DTOs\PublishedEntity;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\ProviderCapability;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Models\AdAccount;
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
    /**
     * Failures the test has asked for, keyed by the method they apply to.
     *
     * `true` stands for "report the grant as revoked", which is a refusal
     * rather than a transport failure and so carries no exception.
     *
     * @var array<string, ProviderUnavailable|true>
     */
    private array $failures = [];

    /** @var list<DiscoveredAsset>|null */
    private ?array $assetOverride = null;

    private ?ProviderAccountState $accountStateOverride = null;

    /**
     * Identifiers handed out, keyed by the idempotency key that made them.
     *
     * @var array<string, string>
     */
    private array $created = [];

    /** @var array<string, string> */
    private array $campaignStates = [];

    private ?CampaignInsights $insightsOverride = null;

    /** @var list<DailyInsightRow>|null */
    private ?array $dailyInsightsOverride = null;

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
    // Publishing
    // ------------------------------------------------------------------

    public function createCampaign(
        AdAccount $account,
        CampaignDraft $draft,
        string $idempotencyKey,
    ): PublishedEntity {
        $this->failIfAsked(__FUNCTION__);

        return $this->createOnce('campaign', $idempotencyKey);
    }

    public function createAdSet(
        AdAccount $account,
        AdSetDraft $draft,
        string $idempotencyKey,
    ): PublishedEntity {
        $this->failIfAsked(__FUNCTION__);

        return $this->createOnce('adset', $idempotencyKey);
    }

    public function createAd(
        AdAccount $account,
        AdDraft $draft,
        string $idempotencyKey,
    ): PublishedEntity {
        $this->failIfAsked(__FUNCTION__);

        // Reads the creative if one was supplied, so a test that forgets to
        // make the bytes reachable fails here rather than in production.
        $stream = $draft->creativeStream();

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $this->createOnce('ad', $idempotencyKey);
    }

    public function setCampaignActive(
        AdAccount $account,
        string $externalCampaignId,
        bool $active,
        string $idempotencyKey,
    ): void {
        $this->failIfAsked(__FUNCTION__);

        $this->campaignStates[$externalCampaignId] = $active ? 'ACTIVE' : 'PAUSED';
    }

    public function stopCampaign(
        AdAccount $account,
        string $externalCampaignId,
        string $idempotencyKey,
    ): void {
        $this->failIfAsked(__FUNCTION__);

        $this->campaignStates[$externalCampaignId] = 'STOPPED';
    }

    public function campaignInsights(AdAccount $account, string $externalCampaignId): CampaignInsights
    {
        $this->failIfAsked(__FUNCTION__);

        if ($this->insightsOverride !== null) {
            return $this->insightsOverride;
        }

        return new CampaignInsights(
            externalCampaignId: $externalCampaignId,
            spendMinor: 0,
            currency: $account->currency,
            impressions: 0,
            clicks: 0,
            status: $this->campaignStates[$externalCampaignId] ?? 'ACTIVE',
            raw: ['mock' => true],
        );
    }

    /**
     * Honours the idempotency key the way a real provider is expected to: the
     * first request with a given key creates something, and every repeat gets
     * back the same identifier marked as pre-existing.
     *
     * The mock behaving correctly here is what lets the publishing pipeline's
     * own protections be tested against something other than a stub that
     * always says yes.
     */
    private function createOnce(string $kind, string $idempotencyKey): PublishedEntity
    {
        if (isset($this->created[$idempotencyKey])) {
            return new PublishedEntity(
                externalId: $this->created[$idempotencyKey],
                status: 'ACTIVE',
                wasExisting: true,
                raw: ['mock' => true, 'idempotent_replay' => true],
            );
        }

        $externalId = sprintf('mock-%s-%s', $kind, Str::lower(Str::random(16)));
        $this->created[$idempotencyKey] = $externalId;

        return new PublishedEntity(
            externalId: $externalId,
            status: 'ACTIVE',
            wasExisting: false,
            raw: ['mock' => true],
        );
    }

    public function campaignDailyInsights(
        AdAccount $account,
        string $externalCampaignId,
        DateTimeImmutable $since,
        DateTimeImmutable $until,
    ): array {
        $this->failIfAsked(__FUNCTION__);

        if ($this->dailyInsightsOverride !== null) {
            return $this->dailyInsightsOverride;
        }

        // A flat, boring series. Tests that care about the numbers set their
        // own; this is here so a caller gets a well-shaped answer rather than
        // an empty one.
        $rows = [];
        $cursor = $since;

        while ($cursor <= $until) {
            $rows[] = new DailyInsightRow(
                date: $cursor,
                spendMinor: 0,
                currency: $account->currency,
                impressions: 0,
                clicks: 0,
                raw: ['mock' => true],
            );

            $cursor = $cursor->modify('+1 day');
        }

        return $rows;
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

    /** Stop a method failing, so a test can let a provider recover. */
    public function willSucceed(string $method): void
    {
        unset($this->failures[$method]);
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

    /** Make the provider report particular figures for every campaign. */
    public function willReportInsights(CampaignInsights $insights): void
    {
        $this->insightsOverride = $insights;
    }

    /**
     * Make the provider report a particular daily series — including a
     * restated one, which is the case ingestion has to survive.
     *
     * @param  list<DailyInsightRow>  $rows
     */
    public function willReportDailyInsights(array $rows): void
    {
        $this->dailyInsightsOverride = $rows;
    }

    /** What the provider currently thinks a published campaign's state is. */
    public function campaignState(string $externalCampaignId): ?string
    {
        return $this->campaignStates[$externalCampaignId] ?? null;
    }

    /** How many distinct entities this adapter has been asked to create. */
    public function creationCount(): int
    {
        return count($this->created);
    }

    private function failIfAsked(string $method): void
    {
        $failure = $this->failures[$method] ?? null;

        if ($failure instanceof ProviderUnavailable) {
            throw $failure;
        }
    }
}
