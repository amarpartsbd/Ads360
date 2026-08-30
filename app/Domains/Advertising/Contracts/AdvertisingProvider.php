<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Contracts;

use App\Domains\Advertising\DTOs\AuthorizationRequest;
use App\Domains\Advertising\DTOs\DiscoveredAsset;
use App\Domains\Advertising\DTOs\ProviderAccountState;
use App\Domains\Advertising\DTOs\ProviderCredentials;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\ProviderCapability;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Integration\Models\ProviderConnection;

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
 * Campaign publishing methods arrive with Phase 4; this interface covers what
 * the advertising foundation needs.
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
}
