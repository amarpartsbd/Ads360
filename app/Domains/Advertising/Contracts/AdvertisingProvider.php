<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Contracts;

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

/**
 * One advertising platform (spec §26).
 *
 * The point of this interface is that nothing above it knows which provider it
 * is talking to. Meta-specific logic lives in the Meta adapter and nowhere
 * else — a generic campaign service that branches on provider has failed the
 * abstraction.
 *
 * Two rules shape the design:
 *
 * §87 — nothing may assume a provider permits a given workflow. Callers ask
 * `supports()` first and degrade gracefully.
 *
 * §27 — adapters use official APIs and authorised assets, and never attempt to
 * work around a provider's policies, limits or review systems. Where a provider
 * refuses something, the adapter surfaces the refusal rather than retrying it
 * differently.
 *
 * Every publishing method takes an idempotency key. Adapters must pass it to
 * the provider so a repeated request is recognised as the same intent rather
 * than acted on twice — creating a campaign twice means spending a client's
 * budget twice (Rule 17).
 */
interface AdvertisingProvider
{
    public function provider(): Provider;

    /** Whether this adapter can do a given thing at all (spec §87). */
    public function supports(ProviderCapability $capability): bool;

    /**
     * Begin authorisation: where to send the client, and the state to verify
     * on the way back (spec §16).
     *
     * @param  list<string>  $scopes  requested permissions; the provider may
     *                                grant fewer, which discovery then reflects
     */
    public function authorizationRequest(string $state, array $scopes = []): AuthorizationRequest;

    /**
     * Exchange an authorisation code for tokens.
     *
     * Always server-side: the code and the resulting tokens never pass through
     * the browser (spec §16, Rule 11).
     *
     * @throws ProviderUnavailable
     */
    public function exchangeCode(string $code): ProviderCredentials;

    /**
     * Renew an access token.
     *
     * @throws ProviderUnavailable when the provider cannot or will not renew
     */
    public function refreshCredentials(ProviderConnection $connection): ProviderCredentials;

    /**
     * Check a connection is still live and the grant still stands.
     *
     * Returning false means the provider disowned it — revoked, expired or
     * disabled — which is a state to record, not an error to retry (spec §27).
     */
    public function verifyConnection(ProviderConnection $connection): bool;

    /**
     * The assets this connection is authorised to use (spec §15).
     *
     * @return list<DiscoveredAsset>
     *
     * @throws ProviderUnavailable
     */
    public function discoverAssets(ProviderConnection $connection): array;

    /**
     * What the provider currently says about a managed ad account (spec §20).
     *
     * The connection is optional because a managed account is not always
     * reached through a client's grant: most of the inventory is operated with
     * the platform's own provider credentials, and only accounts that a client
     * authorised us into need one. An adapter that cannot answer without a
     * connection should say so with ProviderUnavailable rather than guess.
     *
     * @throws ProviderUnavailable
     */
    public function accountState(string $externalAccountId, ?ProviderConnection $connection = null): ProviderAccountState;

    /**
     * Create a campaign at the provider (spec §21, §26).
     *
     * The key must reach the provider. An adapter that drops it turns a
     * network timeout into a duplicate campaign, because the platform cannot
     * tell whether the first request landed.
     *
     * @throws ProviderUnavailable
     */
    public function createCampaign(
        AdAccount $account,
        CampaignDraft $draft,
        string $idempotencyKey,
    ): PublishedEntity;

    /**
     * @throws ProviderUnavailable
     */
    public function createAdSet(
        AdAccount $account,
        AdSetDraft $draft,
        string $idempotencyKey,
    ): PublishedEntity;

    /**
     * @throws ProviderUnavailable
     */
    public function createAd(
        AdAccount $account,
        AdDraft $draft,
        string $idempotencyKey,
    ): PublishedEntity;

    /**
     * Pause or resume a published campaign (spec §21).
     *
     * Repeats are harmless by design: asking to pause something already paused
     * is a no-op, so a retry costs nothing.
     *
     * @throws ProviderUnavailable
     */
    public function setCampaignActive(
        AdAccount $account,
        string $externalCampaignId,
        bool $active,
        string $idempotencyKey,
    ): void;

    /**
     * Stop a campaign for good.
     *
     * Separate from pausing because it is not reversible at most providers,
     * and the platform should not pretend otherwise.
     *
     * @throws ProviderUnavailable
     */
    public function stopCampaign(
        AdAccount $account,
        string $externalCampaignId,
        string $idempotencyKey,
    ): void;

    /**
     * What the provider reports about a running campaign (spec §78).
     *
     * @throws ProviderUnavailable
     */
    public function campaignInsights(AdAccount $account, string $externalCampaignId): CampaignInsights;

    /**
     * Day-by-day performance for a campaign over a window (spec §38, §78).
     *
     * Separate from `campaignInsights()` because the two answer different
     * questions. That one asks "how much has this spent in total", which is
     * what the ledger reconciles against. This one asks "what happened on each
     * day", which is what a client's chart is drawn from — and providers
     * restate past days as attribution windows close, so a caller re-fetches a
     * trailing window rather than only asking about yesterday.
     *
     * Dates in the returned rows are the provider's own, in the ad account's
     * timezone.
     *
     * @return list<DailyInsightRow>
     *
     * @throws ProviderUnavailable
     */
    public function campaignDailyInsights(
        AdAccount $account,
        string $externalCampaignId,
        DateTimeImmutable $since,
        DateTimeImmutable $until,
    ): array;
}
