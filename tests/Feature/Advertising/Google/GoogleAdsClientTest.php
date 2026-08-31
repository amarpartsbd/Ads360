<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising\Google;

use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Providers\Google\DuplicateResourceName;
use App\Domains\Advertising\Providers\Google\GoogleAdsClient;
use App\Domains\Advertising\Providers\Google\GoogleAdsErrorMapper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesGoogleAds;
use Tests\TestCase;

/**
 * The Google Ads transport (spec §29, §80, Rule 12).
 *
 * These tests are about what the platform does with what Google says — the
 * two credentials every request carries, the manager header, the mapping from
 * error families to retryable or not, and the pagination. They cannot prove
 * Google agrees with the request shapes; only a real developer token can.
 */
final class GoogleAdsClientTest extends TestCase
{
    use FakesGoogleAds;

    private function client(?string $token = 'test-access-token'): GoogleAdsClient
    {
        return new GoogleAdsClient($this->googleConfig(), new GoogleAdsErrorMapper, $token);
    }

    #[Test]
    public function the_api_version_is_pinned_in_every_url(): void
    {
        Http::fake(['*' => Http::response($this->googleSearch([]))]);

        $this->client()->search('1234567890', 'SELECT campaign.id FROM campaign');

        Http::assertSent(
            fn (Request $request): bool => str_contains($request->url(), '/v21/customers/1234567890/'),
        );
    }

    #[Test]
    public function the_token_travels_as_a_header_and_never_in_the_url(): void
    {
        Http::fake(['*' => Http::response($this->googleSearch([]))]);

        $this->client('super-secret-token')->search('1234567890', 'SELECT campaign.id FROM campaign');

        $this->assertGoogleTokenNeverInQueryString('super-secret-token');

        Http::assertSent(
            fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer super-secret-token'),
        );
    }

    #[Test]
    public function every_request_carries_the_developer_token(): void
    {
        Http::fake(['*' => Http::response($this->googleSearch([]))]);

        $this->client()->search('1234567890', 'SELECT campaign.id FROM campaign');

        // Without it Google refuses the call whoever is authenticated, with an
        // error that names nothing useful.
        Http::assertSent(
            fn (Request $request): bool => $request->hasHeader('developer-token', 'test-developer-token'),
        );
    }

    #[Test]
    public function the_manager_account_is_named_on_ads_calls(): void
    {
        Http::fake(['*' => Http::response($this->googleSearch([]))]);

        $this->client()->search('1234567890', 'SELECT campaign.id FROM campaign');

        // How a managed ad account is reached at all (spec §17).
        Http::assertSent(
            fn (Request $request): bool => $request->hasHeader('login-customer-id', '9998887776'),
        );
    }

    #[Test]
    public function a_client_grant_acts_through_its_own_account_not_our_manager(): void
    {
        Http::fake(['*' => Http::response($this->googleSearch([]))]);

        $this->client()
            ->withManagerAccount('123-456-7890')
            ->search('1234567890', 'SELECT campaign.id FROM campaign');

        // A client's account is not under the platform's manager, and naming
        // ours would have Google refuse a request they are entitled to make.
        Http::assertSent(
            fn (Request $request): bool => $request->hasHeader('login-customer-id', '1234567890'),
        );
    }

    #[Test]
    public function listing_what_a_user_can_reach_is_not_scoped_to_our_manager(): void
    {
        Http::fake(['*' => Http::response(['resourceNames' => ['customers/1234567890']])]);

        $this->assertSame(['1234567890'], $this->client()->listAccessibleCustomers());

        // A question about the user, not about a manager account.
        Http::assertSent(
            fn (Request $request): bool => ! $request->hasHeader('login-customer-id'),
        );
    }

    #[Test]
    public function a_customer_id_is_sent_as_digits_however_it_arrives(): void
    {
        Http::fake(['*' => Http::response($this->googleSearch([]))]);

        $this->client()->search('123-456-7890', 'SELECT campaign.id FROM campaign');

        Http::assertSent(
            fn (Request $request): bool => str_contains($request->url(), '/customers/1234567890/'),
        );
    }

    #[Test]
    public function something_that_is_not_a_customer_id_is_refused_before_it_reaches_google(): void
    {
        Http::fake(['*' => Http::response($this->googleSearch([]))]);

        $this->expectException(ProviderUnavailable::class);

        $this->client()->search('not-an-account', 'SELECT campaign.id FROM campaign');
    }

    #[Test]
    public function pagination_follows_the_page_token_until_google_stops_sending_one(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->googleSearch([['campaign' => ['id' => '1']]], 'page-2'))
                ->push($this->googleSearch([['campaign' => ['id' => '2']]], 'page-3'))
                // Google omits the token entirely on the last page rather than
                // returning an empty one.
                ->push($this->googleSearch([['campaign' => ['id' => '3']]])),
        ]);

        $rows = $this->client()->search('1234567890', 'SELECT campaign.id FROM campaign');

        $this->assertCount(3, $rows);
    }

    #[Test]
    public function pagination_is_bounded_so_a_large_account_cannot_walk_forever(): void
    {
        Http::fake(['*' => Http::response($this->googleSearch([['campaign' => ['id' => '1']]], 'always-more'))]);

        $rows = $this->client()->search('1234567890', 'SELECT campaign.id FROM campaign', maxPages: 3);

        $this->assertCount(3, $rows);
    }

    #[Test]
    #[DataProvider('errorFamilies')]
    public function google_error_families_map_to_the_right_kind_of_failure(
        string $family,
        string $code,
        bool $retryable,
    ): void {
        Http::fake(['*' => Http::response($this->googleError($family, $code), 400)]);

        try {
            $this->client()->search('1234567890', 'SELECT campaign.id FROM campaign');
            $this->fail('The call should have thrown.');
        } catch (ProviderUnavailable $exception) {
            $this->assertSame(
                $retryable,
                $exception->retryable,
                "{$family}.{$code} should ".($retryable ? '' : 'not ').'be retryable.',
            );
        }
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function errorFamilies(): array
    {
        return [
            // Worth trying again, later.
            'quota' => ['quotaError', 'RESOURCE_EXHAUSTED', true],
            'internal' => ['internalError', 'INTERNAL_ERROR', true],

            // The grant is gone; retrying with the same token cannot help.
            'authentication' => ['authenticationError', 'OAUTH_TOKEN_EXPIRED', false],

            // Google's own decision, never worked around (§27).
            'authorization' => ['authorizationError', 'USER_PERMISSION_DENIED', false],
            'policy' => ['policyFindingError', 'POLICY_FINDING', false],
            'billing' => ['billingSetupError', 'BILLING_SETUP_REQUIRED', false],

            // A malformed resource name will be malformed next time too.
            'malformed resource' => ['requestError', 'RESOURCE_NAME_MALFORMED', false],
        ];
    }

    #[Test]
    public function a_duplicate_name_is_recognised_as_its_own_kind_of_refusal(): void
    {
        Http::fake(['*' => Http::response($this->googleError('campaignError', 'DUPLICATE_NAME'), 400)]);

        try {
            $this->client()->mutate('1234567890', 'campaigns', [['create' => ['name' => 'x']]]);
            $this->fail('The call should have thrown.');
        } catch (DuplicateResourceName $exception) {
            // Not a failure: Google enforcing, on its own side, the guarantee
            // the platform needs (Rule 17).
            $this->assertFalse($exception->retryable);
        }
    }

    #[Test]
    public function a_server_error_is_transient_even_with_no_ads_failure_in_it(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'backend error']], 503)]);

        try {
            $this->client()->search('1234567890', 'SELECT campaign.id FROM campaign');
            $this->fail('The call should have thrown.');
        } catch (ProviderUnavailable $exception) {
            $this->assertTrue($exception->retryable);
        }
    }

    #[Test]
    public function a_refusal_is_not_retried(): void
    {
        Http::fake(['*' => Http::response($this->googleError('authorizationError', 'USER_PERMISSION_DENIED'), 403)]);

        $client = new GoogleAdsClient(
            // Three attempts configured, so a retry would be visible.
            new \App\Domains\Advertising\Providers\Google\GoogleAdsConfig(
                clientId: 'id', clientSecret: 'secret', developerToken: 'token',
                apiVersion: 'v21', apiUrl: 'https://googleads.googleapis.test',
                authUrl: 'https://accounts.google.test/auth',
                tokenUrl: 'https://oauth2.googleapis.test/token',
                userInfoUrl: 'https://openidconnect.googleapis.test/v1/userinfo',
                redirectUri: 'https://ads360.test/callback',
                scopes: [], requestTimeout: 5, connectTimeout: 2,
                maxAttempts: 3, retryDelayMilliseconds: 0,
            ),
            new GoogleAdsErrorMapper,
            'test-access-token',
        );

        try {
            $client->search('1234567890', 'SELECT campaign.id FROM campaign');
        } catch (ProviderUnavailable) {
            // Expected.
        }

        // Re-sending a decision spends quota to be refused identically (§27).
        $this->assertCount(1, $this->recordedGoogleRequests());
    }

    #[Test]
    public function a_mutate_that_returns_no_resource_name_is_a_failure_not_a_success(): void
    {
        Http::fake(['*' => Http::response(['results' => [[]]])]);

        $this->expectException(ProviderUnavailable::class);

        $this->client()->mutateOne('1234567890', 'campaigns', ['create' => ['name' => 'x']]);
    }

    #[Test]
    public function a_mutate_never_asks_for_partial_failure(): void
    {
        Http::fake(['*' => Http::response($this->googleMutated('customers/1/campaigns/2'))]);

        $this->client()->mutateOne('1234567890', 'campaigns', ['create' => ['name' => 'x']]);

        Http::assertSent(function (Request $request): bool {
            // A partial failure would leave a half-built campaign that the
            // publication ledger would record as a success (Rule 15).
            return ($request->data()['partialFailure'] ?? false) === false;
        });
    }

    #[Test]
    public function an_oauth_failure_is_read_from_its_own_envelope(): void
    {
        Http::fake([
            '*token*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'revoked'], 400),
        ]);

        try {
            $this->client()->token(['grant_type' => 'refresh_token']);
            $this->fail('The call should have thrown.');
        } catch (ProviderUnavailable $exception) {
            // A revoked refresh token: the client has to reconnect, and no
            // amount of retrying will change that.
            $this->assertFalse($exception->retryable);
            $this->assertStringContainsString('reconnect', $exception->clientMessage);
        }
    }

    #[Test]
    public function a_gaql_literal_cannot_break_out_of_its_quotes(): void
    {
        $escaped = GoogleAdsClient::escape("O'Brien's \\ Motors\nDhaka");

        $this->assertStringNotContainsString("'", str_replace("\\'", '', $escaped));
        $this->assertStringNotContainsString("\n", $escaped);
    }
}
