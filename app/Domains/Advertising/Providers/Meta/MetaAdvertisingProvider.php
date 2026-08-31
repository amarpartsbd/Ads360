<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Providers\Meta;

use App\Domains\Advertising\Contracts\AdvertisingProvider;
use App\Domains\Advertising\DTOs\AuthorizationRequest;
use App\Domains\Advertising\DTOs\ProviderCredentials;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\ProviderCapability;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Integration\Models\ProviderConnection;
use DateTimeImmutable;

/**
 * The live Meta adapter (spec §26, §27).
 *
 * Everything Meta-specific lives here and nowhere else: the vocabulary
 * translation, the field names, the two-step ad creation, the fact that Meta
 * measures money in an account's own minor units. Callers above this class
 * work in the platform's own terms.
 *
 * Two things about this adapter are worth knowing before reading it.
 *
 * **Meta has no idempotency-key header.** The Marketing API offers no general
 * way to say "this is the same request as before". The key the contract passes
 * is therefore used differently here: it is carried in the created object's
 * name, and every creation is preceded by a lookup for an object already
 * bearing that reference. That is a real round trip and a real cost, and it is
 * the honest way to get the guarantee — see `existing()`.
 *
 * **Nothing here works around a refusal.** Meta declining a campaign, an
 * account restriction, a spend limit or a review outcome is reported as it
 * stands. There is no retry with different parameters, no second account, no
 * attempt to make a refusal look like something else (§27).
 *
 * **Two kinds of credential, and they are not interchangeable.** A client's
 * grant reaches the assets that client authorised; the platform's own system
 * user token reaches the managed ad accounts the platform owns (spec §17).
 * Which one a call uses is decided here, once, by whether a connection was
 * passed — see platformClient().
 */
final class MetaAdvertisingProvider implements AdvertisingProvider
{
    use MetaPublishesCampaigns;
    use MetaReadsAssets;

    /**
     * The client authenticated as the platform itself, once resolved.
     * Memoised for the life of this adapter, which ProviderManager keeps for
     * one request or one job.
     */
    private ?MetaGraphClient $platform = null;

    public function __construct(
        private readonly MetaConfig $config,
        private readonly MetaGraphClient $client,
    ) {}

    public function provider(): Provider
    {
        return Provider::Meta;
    }

    /**
     * What this adapter genuinely does (spec §87).
     *
     * Webhooks are listed because the receiver exists; managed ad accounts and
     * spend limits are listed because Meta exposes both. Lead forms are not:
     * retrieving lead data needs its own permission and its own handling of
     * personal data, and claiming it before that is built would have callers
     * offer clients something that does not work.
     */
    public function supports(ProviderCapability $capability): bool
    {
        return match ($capability) {
            ProviderCapability::LeadForms => false,
            default => true,
        };
    }

    // ------------------------------------------------------------------
    // Authorisation
    // ------------------------------------------------------------------

    public function authorizationRequest(string $state, array $scopes = []): AuthorizationRequest
    {
        $this->config->assertUsable();

        $requested = $scopes !== [] ? $scopes : $this->config->scopes;

        $url = sprintf(
            '%s/%s/dialog/oauth?%s',
            rtrim($this->config->dialogUrl, '/'),
            $this->config->apiVersion,
            http_build_query([
                'client_id' => $this->config->appId,
                'redirect_uri' => $this->config->redirectUri,
                'state' => $state,
                'scope' => implode(',', $requested),
                'response_type' => 'code',
                // Forces the permission screen even for a returning user, so a
                // client re-authorising after losing a permission is actually
                // asked for it again rather than silently re-granted the old set.
                'auth_type' => 'rerequest',
            ]),
        );

        return new AuthorizationRequest(url: $url, state: $state, scopes: $requested);
    }

    /**
     * Exchange the code for a token, then immediately exchange that for a
     * long-lived one.
     *
     * The short-lived token Meta returns from the code exchange lasts about an
     * hour, which is useless for a platform that publishes campaigns on a
     * schedule. The second exchange is not optional.
     */
    public function exchangeCode(string $code): ProviderCredentials
    {
        $this->config->assertUsable();

        $short = $this->client->get('oauth/access_token', [
            'client_id' => $this->config->appId,
            'client_secret' => $this->config->appSecret,
            'redirect_uri' => $this->config->redirectUri,
            'code' => $code,
        ]);

        $token = (string) ($short['access_token'] ?? '');

        if ($token === '') {
            throw ProviderUnavailable::authenticationFailed(
                Provider::Meta,
                'the code exchange returned no access token',
            );
        }

        return $this->longLived($token);
    }

    /**
     * Renew a grant.
     *
     * Meta has no refresh token: a long-lived token is exchanged for a fresh
     * long-lived token using itself. That only works while the current one is
     * still valid, which is why connection health refreshes ahead of expiry
     * rather than on it.
     */
    public function refreshCredentials(ProviderConnection $connection): ProviderCredentials
    {
        $this->config->assertUsable();

        if (! $connection->hasCredentials()) {
            throw ProviderUnavailable::authenticationFailed(
                Provider::Meta,
                'the connection holds no token to exchange',
            );
        }

        return $this->longLived($connection->accessToken());
    }

    /**
     * Ask Meta whether the token is still good, and whether it still carries
     * the permissions the platform needs.
     *
     * A token can remain valid while a client revokes one permission from
     * their Facebook settings. That leaves a connection that authenticates
     * fine and cannot publish, so the scopes are checked too.
     */
    public function verifyConnection(ProviderConnection $connection): bool
    {
        if (! $connection->hasCredentials()) {
            return false;
        }

        try {
            $response = $this->client->get('debug_token', [
                'input_token' => $connection->accessToken(),
                'access_token' => $this->config->appAccessToken(),
            ]);
        } catch (ProviderUnavailable $exception) {
            // A token Meta refuses to describe is a token we cannot use. A
            // transient failure, though, says nothing either way.
            if ($exception->retryable) {
                throw $exception;
            }

            return false;
        }

        $data = $response['data'] ?? [];

        if (($data['is_valid'] ?? false) !== true) {
            return false;
        }

        $granted = array_map('strval', (array) ($data['scopes'] ?? []));

        // ads_management is the one without which nothing can be published.
        return in_array('ads_management', $granted, true);
    }

    /**
     * A client authenticated as the platform itself.
     *
     * Most of the inventory is operated with the platform's own credentials
     * rather than a client's (spec §17): a managed ad account has no
     * connection behind it, and Meta authenticates every call. Publishing,
     * lifecycle control, insights and the health of a managed account all go
     * through here.
     *
     * Unlike Google, there is nothing to exchange. A Meta system user token is
     * already the access token — it is issued once in Business Manager and does
     * not expire on a clock — so this is a token read from configuration rather
     * than a round trip. What it *can* do is stop working: a regenerated or
     * revoked token fails as an authentication error, which the error mapper
     * already reports as non-retryable so the queue tells someone rather than
     * hammering Meta.
     *
     * @throws ProviderUnavailable
     */
    private function platformClient(): MetaGraphClient
    {
        if ($this->platform !== null) {
            return $this->platform;
        }

        $this->config->assertUsable();

        if ($this->config->systemUserToken === null) {
            /*
             * Named rather than left to arrive as a 400 from Meta. Without the
             * platform's own grant nothing can be published to a managed
             * account at all, and the error an operator sees should say which
             * variable is missing.
             */
            throw ProviderUnavailable::authenticationFailed(
                Provider::Meta,
                'no platform grant is configured; set META_SYSTEM_USER_TOKEN',
            );
        }

        return $this->platform = $this->client->withToken($this->config->systemUserToken);
    }

    /**
     * Trade a token for a long-lived one and describe the result.
     *
     * @throws ProviderUnavailable
     */
    private function longLived(string $token): ProviderCredentials
    {
        $exchanged = $this->client->get('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->config->appId,
            'client_secret' => $this->config->appSecret,
            'fb_exchange_token' => $token,
        ]);

        $accessToken = (string) ($exchanged['access_token'] ?? '');

        if ($accessToken === '') {
            throw ProviderUnavailable::authenticationFailed(
                Provider::Meta,
                'the long-lived exchange returned no access token',
            );
        }

        $authorised = $this->client->withToken($accessToken);
        $identity = $authorised->get('me', ['fields' => 'id,name']);

        $expiresAt = null;

        if (isset($exchanged['expires_in']) && (int) $exchanged['expires_in'] > 0) {
            $expiresAt = (new DateTimeImmutable)->modify('+'.(int) $exchanged['expires_in'].' seconds');
        }

        return new ProviderCredentials(
            accessToken: $accessToken,
            externalUserId: (string) ($identity['id'] ?? ''),
            // Meta issues no refresh token; renewal re-exchanges this one.
            refreshToken: null,
            expiresAt: $expiresAt,
            scopes: $this->grantedScopes($accessToken),
            accountName: isset($identity['name']) ? (string) $identity['name'] : null,
        );
    }

    /**
     * What Meta actually granted, which may be less than what was asked for.
     * Discovery and publishing both depend on knowing the difference (§87).
     *
     * @return list<string>
     */
    private function grantedScopes(string $accessToken): array
    {
        try {
            $response = $this->client->get('debug_token', [
                'input_token' => $accessToken,
                'access_token' => $this->config->appAccessToken(),
            ]);
        } catch (ProviderUnavailable) {
            // Not worth failing a working connection over. The scopes are a
            // record, and connection health will establish them later.
            return [];
        }

        return array_values(array_map('strval', (array) ($response['data']['scopes'] ?? [])));
    }
}
