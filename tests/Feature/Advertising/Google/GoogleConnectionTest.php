<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising\Google;

use App\Domains\Advertising\Enums\AssetType;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Integration\Models\ProviderConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesGoogleAds;
use Tests\TestCase;

/**
 * Authorising, renewing and reading a Google grant (spec §16, §15).
 *
 * The renewal tests are the important ones. Google, unlike Meta, issues a real
 * refresh token — and a connection that ends up without one works for an hour
 * and then fails during an overnight publish, which is the worst possible time
 * to discover it.
 */
final class GoogleConnectionTest extends TestCase
{
    use FakesGoogleAds;
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function connection(array $attributes = []): ProviderConnection
    {
        $connection = ProviderConnection::factory()->create([
            'provider' => Provider::Google,
            'external_user_id' => '110000000000000000001',
        ] + $attributes);

        $connection->access_token_encrypted = 'stored-access-token';
        $connection->refresh_token_encrypted = 'stored-refresh-token';
        $connection->save();

        return $connection;
    }

    #[Test]
    public function the_authorisation_url_asks_for_a_refresh_token_explicitly(): void
    {
        $request = $this->googleAdapter()->authorizationRequest('state-123');

        parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);

        /*
         * Both are load-bearing. Without offline access Google issues no
         * refresh token at all; without a forced consent a returning client is
         * silently re-granted an access token with no way to renew it.
         */
        $this->assertSame('offline', $query['access_type'] ?? null);
        $this->assertSame('consent', $query['prompt'] ?? null);
        $this->assertSame('state-123', $query['state'] ?? null);
        $this->assertStringContainsString('auth/adwords', $query['scope'] ?? '');
    }

    #[Test]
    public function the_client_secret_never_appears_in_the_authorisation_url(): void
    {
        $request = $this->googleAdapter()->authorizationRequest('state-123');

        // The client goes to this URL in their own browser (Rule 11).
        $this->assertStringNotContainsString('test-client-secret', $request->url);
    }

    #[Test]
    public function exchanging_a_code_stores_the_refresh_token_and_the_google_identity(): void
    {
        Http::fake([
            '*token*' => Http::response([
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'fresh-refresh-token',
                'expires_in' => 3599,
                'scope' => 'https://www.googleapis.com/auth/adwords openid email',
            ]),
            '*userinfo*' => Http::response([
                'sub' => '110000000000000000001',
                'email' => 'ads@client.test',
            ]),
        ]);

        $credentials = $this->googleAdapter()->exchangeCode('auth-code');

        $this->assertSame('fresh-access-token', $credentials->accessToken);
        $this->assertSame('fresh-refresh-token', $credentials->refreshToken);
        $this->assertSame('110000000000000000001', $credentials->externalUserId);
        $this->assertSame('ads@client.test', $credentials->accountName);
        $this->assertContains('https://www.googleapis.com/auth/adwords', $credentials->scopes);
    }

    #[Test]
    public function an_exchange_that_returns_no_refresh_token_is_refused(): void
    {
        Http::fake([
            '*token*' => Http::response(['access_token' => 'fresh-access-token', 'expires_in' => 3599]),
        ]);

        try {
            $this->googleAdapter()->exchangeCode('auth-code');
            $this->fail('The exchange should have been refused.');
        } catch (ProviderUnavailable $exception) {
            /*
             * Stored, this connection would stop working within the hour and
             * would fail during a publish rather than here. Telling the client
             * now, while they are looking at the screen, is the honest outcome.
             */
            $this->assertFalse($exception->retryable);
            $this->assertStringContainsString('refresh token', $exception->getMessage());
        }
    }

    #[Test]
    public function an_unidentifiable_grant_is_refused_rather_than_given_an_invented_identity(): void
    {
        Http::fake([
            '*token*' => Http::response([
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'fresh-refresh-token',
            ]),
            '*userinfo*' => Http::response([], 403),
        ]);

        // Inventing an identity would let the same Google account connect
        // twice, each holding its own token over the same customer accounts.
        $this->expectException(ProviderUnavailable::class);

        $this->googleAdapter()->exchangeCode('auth-code');
    }

    #[Test]
    public function a_refresh_uses_the_stored_refresh_token_and_keeps_the_identity(): void
    {
        Http::fake([
            '*token*' => Http::response([
                'access_token' => 'renewed-access-token',
                'expires_in' => 3599,
            ]),
        ]);

        $connection = $this->connection();

        $credentials = $this->googleAdapter()->refreshCredentials($connection);

        $this->assertSame('renewed-access-token', $credentials->accessToken);
        $this->assertSame('110000000000000000001', $credentials->externalUserId);

        /*
         * Google returns a refresh token on renewal only when it has rotated
         * one. Null means "keep the one you have" — reporting the absence as a
         * new value would throw away the means to refresh again.
         */
        $this->assertNull($credentials->refreshToken);

        Http::assertSent(function (Request $request): bool {
            return ($request->data()['grant_type'] ?? null) === 'refresh_token'
                && ($request->data()['refresh_token'] ?? null) === 'stored-refresh-token';
        });
    }

    #[Test]
    public function a_refresh_keeps_the_recorded_scopes_when_google_does_not_repeat_them(): void
    {
        Http::fake(['*token*' => Http::response(['access_token' => 'renewed', 'expires_in' => 3599])]);

        $connection = $this->connection(['scopes' => ['https://www.googleapis.com/auth/adwords']]);

        $credentials = $this->googleAdapter()->refreshCredentials($connection);

        // An empty set would look like the client had revoked everything.
        $this->assertSame(['https://www.googleapis.com/auth/adwords'], $credentials->scopes);
    }

    #[Test]
    public function a_connection_with_no_refresh_token_cannot_be_renewed(): void
    {
        $connection = $this->connection();
        $connection->refresh_token_encrypted = null;
        $connection->save();

        $this->expectException(ProviderUnavailable::class);

        $this->googleAdapter()->refreshCredentials($connection);
    }

    #[Test]
    public function an_expired_access_token_is_not_reported_as_a_revoked_grant(): void
    {
        Http::fake([
            '*listAccessibleCustomers*' => Http::response(
                $this->googleError('authenticationError', 'OAUTH_TOKEN_EXPIRED'),
                401,
            ),
            '*token*' => Http::response(['access_token' => 'renewed', 'expires_in' => 3599]),
        ]);

        /*
         * Google's access token lives about an hour; the durable credential is
         * the refresh token. Sending a client to reconnect an account that
         * never stopped working would be a support call we caused.
         */
        $this->assertTrue($this->googleAdapter()->verifyConnection($this->connection()));
    }

    #[Test]
    public function a_revoked_grant_is_reported_as_gone(): void
    {
        Http::fake([
            '*listAccessibleCustomers*' => Http::response(
                $this->googleError('authenticationError', 'OAUTH_TOKEN_REVOKED'),
                401,
            ),
            '*token*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->assertFalse($this->googleAdapter()->verifyConnection($this->connection()));
    }

    #[Test]
    public function a_transient_failure_does_not_decide_anything_about_the_grant(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'backend error']], 503)]);

        // Google having a bad moment says nothing either way, so it raises
        // rather than marking a healthy connection dead.
        $this->expectException(ProviderUnavailable::class);

        $this->googleAdapter()->verifyConnection($this->connection());
    }

    #[Test]
    public function discovery_expands_a_manager_account_into_the_accounts_beneath_it(): void
    {
        Http::fake([
            '*listAccessibleCustomers*' => Http::response(['resourceNames' => ['customers/9998887776']]),
            '*googleAds:search*' => Http::response($this->googleSearch([
                ['customerClient' => [
                    'id' => '9998887776',
                    'descriptiveName' => 'Amar Parts — Manager',
                    'currencyCode' => 'BDT',
                    'timeZone' => 'Asia/Dhaka',
                    'manager' => true,
                    'status' => 'ENABLED',
                    'level' => 0,
                ]],
                ['customerClient' => [
                    'id' => '1234567890',
                    'descriptiveName' => 'Amar Parts — Retail',
                    'currencyCode' => 'BDT',
                    'timeZone' => 'Asia/Dhaka',
                    'manager' => false,
                    'status' => 'ENABLED',
                    'level' => 1,
                ]],
            ])),
        ]);

        $assets = $this->googleAdapter()->discoverAssets($this->connection());

        /*
         * The point of the expansion: listAccessibleCustomers returns the one
         * manager account, and the accounts that actually run ads are beneath
         * it.
         */
        $this->assertCount(2, $assets);
        $this->assertSame(AssetType::GoogleAdsAccount, $assets[0]->type);
        $this->assertSame('1234567890', $assets[1]->externalId);
        $this->assertTrue($assets[0]->metadata['manager_account']);
        $this->assertFalse($assets[1]->metadata['manager_account']);
    }

    #[Test]
    public function one_inaccessible_account_does_not_sink_the_rest(): void
    {
        Http::fake([
            '*listAccessibleCustomers*' => Http::response([
                'resourceNames' => ['customers/1111111111', 'customers/2222222222'],
            ]),
            '*googleAds:search*' => Http::sequence()
                ->push($this->googleError('authorizationError', 'USER_PERMISSION_DENIED'), 403)
                ->push($this->googleSearch([
                    ['customerClient' => [
                        'id' => '2222222222',
                        'descriptiveName' => 'Reachable',
                        'currencyCode' => 'BDT',
                        'manager' => false,
                        'status' => 'ENABLED',
                    ]],
                ])),
        ]);

        $assets = $this->googleAdapter()->discoverAssets($this->connection());

        // Access is granted per account in a manager hierarchy, not per tree.
        $this->assertCount(1, $assets);
        $this->assertSame('2222222222', $assets[0]->externalId);
    }

    #[Test]
    public function an_account_reachable_through_two_managers_is_listed_once(): void
    {
        Http::fake([
            '*listAccessibleCustomers*' => Http::response([
                'resourceNames' => ['customers/1111111111', 'customers/2222222222'],
            ]),
            '*googleAds:search*' => Http::response($this->googleSearch([
                ['customerClient' => [
                    'id' => '3333333333',
                    'descriptiveName' => 'Shared child',
                    'currencyCode' => 'BDT',
                    'manager' => false,
                    'status' => 'ENABLED',
                ]],
            ])),
        ]);

        $this->assertCount(1, $this->googleAdapter()->discoverAssets($this->connection()));
    }
}
