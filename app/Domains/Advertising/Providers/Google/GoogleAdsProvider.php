<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Providers\Google;

use App\Domains\Advertising\Contracts\AdvertisingProvider;
use App\Domains\Advertising\DTOs\AuthorizationRequest;
use App\Domains\Advertising\DTOs\ProviderCredentials;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\ProviderCapability;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Integration\Models\ProviderConnection;
use DateTimeImmutable;

/**
 * The live Google Ads adapter (spec §26, §27, §87).
 *
 * This is the second live adapter, and it exists partly to prove that the
 * contract in AdvertisingProvider is a real abstraction rather than Meta's
 * shape with a different name on it. Google's API disagrees with Meta's on
 * almost every axis, and every one of those disagreements is absorbed here:
 *
 *   - **Money is in micros**, a millionth of a currency unit, rather than in
 *     the account's minor units. See Micros.
 *   - **Reads are a query language.** GAQL against `googleAds:search`, not
 *     field lists on an object path.
 *   - **Objects have resource names**, `customers/123/campaigns/456`, not
 *     bare numeric ids. The platform stores the whole resource name, because
 *     that is what every subsequent call wants.
 *   - **A campaign needs a budget object first.** Two calls, not one, and the
 *     budget is a first-class resource that outlives the campaign.
 *   - **Refresh tokens are real.** Unlike Meta, where a long-lived token is
 *     re-exchanged for itself, Google issues a refresh token that renews an
 *     access token without the client being present.
 *   - **There are no webhooks.** Google has no push mechanism for ad account
 *     or campaign changes, so `supports()` says so and callers take the
 *     polling path. This is exactly the §87 fallback existing for a reason.
 *
 * ## Idempotency here is better than at Meta, and it is Google's doing
 *
 * The Ads API has no idempotency-key header either. But it does enforce, on
 * its own side, that a campaign name is unique within a customer account and
 * that an ad group name is unique within a campaign. So a repeated creation is
 * *refused by Google* rather than silently duplicated — which is the guarantee
 * the platform needs, granted by the provider instead of approximated by us.
 *
 * The adapter leans on that deliberately: it embeds the platform's reference
 * in each object's name, looks the reference up before creating, and treats a
 * duplicate-name refusal as proof that a previous attempt landed. See
 * GooglePublishesCampaigns.
 *
 * ## Nothing here works around a refusal
 *
 * Google declining a campaign on policy grounds, an account suspension, a
 * billing hold or an eligibility decision is reported as it stands. There is
 * no retry with different parameters and no second account (§27).
 */
final class GoogleAdsProvider implements AdvertisingProvider
{
    use GooglePublishesCampaigns;
    use GoogleReadsAssets;

    /**
     * The client authenticated as the platform itself, once it has been
     * obtained. Memoised for the life of this adapter, which ProviderManager
     * keeps for one request or one job.
     */
    private ?GoogleAdsClient $platform = null;

    public function __construct(
        private readonly GoogleAdsConfig $config,
        private readonly GoogleAdsClient $client,
    ) {}

    public function provider(): Provider
    {
        return Provider::Google;
    }

    /**
     * What this adapter genuinely does (spec §87).
     *
     * Three honest noes, and they are the point of this method existing:
     *
     * **Webhooks.** Google Ads has no push notification for account or
     * campaign state. Callers that check this take the polling path.
     *
     * **Spend limits.** Google exposes an account-level budget only for
     * accounts on monthly invoicing, which most are not. Reporting a limit
     * this adapter cannot actually read would have the allocation engine make
     * decisions from a figure that was never true (§20).
     *
     * **Lead forms.** Same reasoning as the Meta adapter: retrieving lead data
     * needs its own handling of personal data, and claiming it before that is
     * built would have callers offer clients something that does not work.
     */
    public function supports(ProviderCapability $capability): bool
    {
        return match ($capability) {
            ProviderCapability::Webhooks,
            ProviderCapability::SpendLimits,
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

        $url = $this->config->authUrl.'?'.http_build_query([
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->config->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $requested),
            'state' => $state,

            /*
             * Both of these are load-bearing.
             *
             * `access_type=offline` is what makes Google issue a refresh token
             * at all. Without it the platform gets an access token that dies
             * in an hour, and a campaign published overnight fails with an
             * expired grant.
             *
             * `prompt=consent` forces the consent screen even for a returning
             * user. Google issues a refresh token only on a fresh consent, so
             * a client who reconnects after we lost their token would
             * otherwise be silently re-granted an access token with no way to
             * renew it — a connection that works for an hour and then does not.
             */
            'access_type' => 'offline',
            'prompt' => 'consent',

            // Keeps scopes granted in an earlier consent rather than
            // replacing them, so reconnecting does not quietly narrow access.
            'include_granted_scopes' => 'true',
        ]);

        return new AuthorizationRequest(url: $url, state: $state, scopes: $requested);
    }

    /**
     * Exchange the authorisation code for tokens.
     *
     * Always server-side: the code, the client secret and the resulting tokens
     * never pass through the browser (spec §16, Rule 11).
     */
    public function exchangeCode(string $code): ProviderCredentials
    {
        $this->config->assertUsable();

        $tokens = $this->client->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
            'redirect_uri' => $this->config->redirectUri,
        ]);

        $accessToken = (string) ($tokens['access_token'] ?? '');

        if ($accessToken === '') {
            throw ProviderUnavailable::authenticationFailed(
                Provider::Google,
                'the code exchange returned no access token',
            );
        }

        $refreshToken = isset($tokens['refresh_token']) ? (string) $tokens['refresh_token'] : null;

        if ($refreshToken === null || $refreshToken === '') {
            /*
             * Refused rather than stored. A Google connection without a
             * refresh token stops working in an hour, and it would fail during
             * a publish rather than here — telling the client to reconnect now,
             * while they are looking at the screen, is the honest outcome.
             */
            throw ProviderUnavailable::authenticationFailed(
                Provider::Google,
                'Google returned no refresh token; the grant would expire within the hour',
            );
        }

        $identity = $this->identity($accessToken);

        return new ProviderCredentials(
            accessToken: $accessToken,
            externalUserId: $identity['id'],
            refreshToken: $refreshToken,
            expiresAt: $this->expiryFrom($tokens),
            scopes: $this->grantedScopes($tokens),
            accountName: $identity['name'],
        );
    }

    /**
     * Renew an access token from the stored refresh token.
     *
     * Unlike Meta, this needs nothing from the client and works long after
     * their last visit — which is what makes overnight publishing and
     * scheduled metric ingestion possible at all.
     */
    public function refreshCredentials(ProviderConnection $connection): ProviderCredentials
    {
        $this->config->assertUsable();

        $refreshToken = $connection->refreshToken();

        if ($refreshToken === null) {
            throw ProviderUnavailable::authenticationFailed(
                Provider::Google,
                'the connection holds no refresh token',
            );
        }

        $tokens = $this->client->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
        ]);

        $accessToken = (string) ($tokens['access_token'] ?? '');

        if ($accessToken === '') {
            throw ProviderUnavailable::authenticationFailed(
                Provider::Google,
                'the refresh returned no access token',
            );
        }

        return new ProviderCredentials(
            accessToken: $accessToken,
            externalUserId: $connection->external_user_id,
            /*
             * Google returns a refresh token on renewal only when it has
             * rotated one. Null here means "keep the one you have", which is
             * what ProviderConnection::storeCredentials does — reporting the
             * absence as a new value would throw away the means to refresh
             * again.
             */
            refreshToken: isset($tokens['refresh_token']) ? (string) $tokens['refresh_token'] : null,
            expiresAt: $this->expiryFrom($tokens),
            scopes: $this->grantedScopes($tokens, $connection),
            accountName: $connection->account_name,
        );
    }

    /**
     * Whether the grant still stands.
     *
     * Two steps, because Google's access token lives about an hour and the
     * durable credential is the refresh token. A connection whose access token
     * has simply aged out is perfectly healthy, and reporting it as revoked
     * would send a client to reconnect an account that never stopped working.
     */
    public function verifyConnection(ProviderConnection $connection): bool
    {
        if (! $connection->hasCredentials()) {
            return false;
        }

        try {
            $this->client->withToken($connection->accessToken())->listAccessibleCustomers();

            return true;
        } catch (ProviderUnavailable $exception) {
            if ($exception->retryable) {
                // Google having a bad moment says nothing either way.
                throw $exception;
            }
        }

        if (! $connection->hasRefreshToken()) {
            return false;
        }

        try {
            // The real question: will Google still renew for us? A refusal
            // here is the grant being gone, which is a state to record rather
            // than an error to retry (§27).
            $this->refreshCredentials($connection);

            return true;
        } catch (ProviderUnavailable $exception) {
            if ($exception->retryable) {
                throw $exception;
            }

            return false;
        }
    }

    /**
     * A client authenticated as the platform itself.
     *
     * Most of the inventory is operated with the platform's own credentials
     * rather than a client's (spec §17): a managed ad account has no client
     * grant behind it, and Google authenticates every call. Publishing,
     * lifecycle control, insights and the health of a managed account all come
     * through here.
     *
     * The access token is obtained from the platform's stored refresh token
     * and memoised for the life of this adapter — one exchange per request or
     * job. It is deliberately *not* written to the cache: a bearer token in a
     * shared cache is a credential at rest somewhere the platform does not
     * encrypt, and one extra HTTP call is a cheap price for not putting it
     * there (Rule 12, §16).
     *
     * @throws ProviderUnavailable
     */
    private function platformClient(): GoogleAdsClient
    {
        return $this->platform ??= $this->client->withToken($this->platformAccessToken());
    }

    /**
     * @throws ProviderUnavailable
     */
    private function platformAccessToken(): string
    {
        $this->config->assertUsable();

        if ($this->config->refreshToken === null) {
            /*
             * Named rather than left to fail as a 401 from Google. Without the
             * platform's own grant, nothing can be published to a managed
             * account at all, and the error an operator sees should say which
             * variable is missing.
             */
            throw ProviderUnavailable::authenticationFailed(
                Provider::Google,
                'no platform grant is configured; set GOOGLE_ADS_REFRESH_TOKEN',
            );
        }

        $tokens = $this->client->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->config->refreshToken,
            'client_id' => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
        ]);

        $token = (string) ($tokens['access_token'] ?? '');

        if ($token === '') {
            throw ProviderUnavailable::authenticationFailed(
                Provider::Google,
                'the platform grant returned no access token',
            );
        }

        return $token;
    }

    /**
     * Which Google account this grant belongs to.
     *
     * The `openid` and `email` scopes buy nothing in advertising terms — they
     * grant no access to any ad account. They exist so the platform knows
     * *whose* grant it holds, which is what makes a reconnection update the
     * existing connection instead of creating a second one beside it, and what
     * lets a client with several Google accounts see which one is attached.
     *
     * @return array{id: string, name: string|null}
     */
    private function identity(string $accessToken): array
    {
        try {
            $profile = $this->client->userInfo($accessToken);
        } catch (ProviderUnavailable) {
            $profile = [];
        }

        $id = isset($profile['sub']) ? (string) $profile['sub'] : '';

        if ($id === '') {
            /*
             * Refused rather than filled in. The external user id is what the
             * platform keys a connection on, and inventing one would let the
             * same Google account connect twice under two different
             * identities — each holding its own token, each publishing to the
             * same customer accounts.
             */
            throw ProviderUnavailable::authenticationFailed(
                Provider::Google,
                'Google did not identify the account this grant belongs to; '
                .'the openid and email scopes may not have been granted',
            );
        }

        $name = $profile['email'] ?? $profile['name'] ?? null;

        return ['id' => $id, 'name' => is_string($name) ? $name : null];
    }

    /**
     * @param  array<string, mixed>  $tokens
     */
    private function expiryFrom(array $tokens): ?DateTimeImmutable
    {
        $seconds = (int) ($tokens['expires_in'] ?? 0);

        return $seconds > 0
            ? (new DateTimeImmutable)->modify("+{$seconds} seconds")
            : null;
    }

    /**
     * What Google actually granted, which may be less than what was asked for
     * (§87). Space-separated, unlike Meta's list.
     *
     * @param  array<string, mixed>  $tokens
     * @return list<string>
     */
    private function grantedScopes(array $tokens, ?ProviderConnection $connection = null): array
    {
        $scope = $tokens['scope'] ?? null;

        if (! is_string($scope) || trim($scope) === '') {
            // A refresh response often omits the scope entirely. Reporting an
            // empty set would look like the client had revoked everything.
            $existing = $connection?->scopes;

            return is_array($existing) ? array_values(array_map('strval', $existing)) : [];
        }

        return array_values(array_filter(explode(' ', $scope)));
    }
}
