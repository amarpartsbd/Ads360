<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising\Meta;

use App\Domains\Advertising\Enums\AssetType;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Integration\Models\ProviderConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesMetaGraph;
use Tests\TestCase;

/**
 * Authorising a Meta account, and reading what the grant covers
 * (spec §15, §16, §87).
 */
final class MetaConnectionTest extends TestCase
{
    use FakesMetaGraph;
    use RefreshDatabase;

    #[Test]
    public function the_authorisation_url_carries_the_state_and_the_configured_scopes(): void
    {
        $request = $this->metaAdapter()->authorizationRequest('a-state-value');

        parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);

        $this->assertSame('a-state-value', $query['state']);
        $this->assertSame('1234567890', $query['client_id']);
        $this->assertStringContainsString('ads_management', $query['scope']);
        // Forces the permission screen, so a client re-authorising after
        // losing a permission is genuinely asked for it again.
        $this->assertSame('rerequest', $query['auth_type']);
    }

    #[Test]
    public function the_authorisation_url_never_carries_the_app_secret(): void
    {
        $request = $this->metaAdapter()->authorizationRequest('a-state-value');

        $this->assertStringNotContainsString('test-app-secret', $request->url);
    }

    #[Test]
    public function a_code_is_exchanged_for_a_long_lived_token_not_the_short_one(): void
    {
        Http::fakeSequence()
            // The code exchange returns a token good for about an hour.
            ->push(['access_token' => 'short-lived-token', 'expires_in' => 3600])
            // Which is immediately traded for one that lasts.
            ->push(['access_token' => 'long-lived-token', 'expires_in' => 5_184_000])
            ->push(['id' => '900100', 'name' => 'Test Business'])
            ->push(['data' => ['is_valid' => true, 'scopes' => ['ads_management', 'ads_read']]]);

        $credentials = $this->metaAdapter()->exchangeCode('an-auth-code');

        // The short-lived token is useless to a platform that publishes on a
        // schedule, so it must not be what gets stored.
        $this->assertSame('long-lived-token', $credentials->accessToken);
        $this->assertSame('900100', $credentials->externalUserId);
        $this->assertSame('Test Business', $credentials->accountName);
        $this->assertContains('ads_management', $credentials->scopes);
        $this->assertNotNull($credentials->expiresAt);
    }

    #[Test]
    public function meta_issues_no_refresh_token_and_the_credentials_say_so(): void
    {
        Http::fakeSequence()
            ->push(['access_token' => 'short-lived-token'])
            ->push(['access_token' => 'long-lived-token', 'expires_in' => 5_184_000])
            ->push(['id' => '900100', 'name' => 'Test Business'])
            ->push(['data' => ['is_valid' => true, 'scopes' => ['ads_management']]]);

        $credentials = $this->metaAdapter()->exchangeCode('an-auth-code');

        // Renewal re-exchanges the long-lived token for a new one; there is no
        // separate refresh credential to store.
        $this->assertNull($credentials->refreshToken);
    }

    #[Test]
    public function an_exchange_that_returns_no_token_is_an_authentication_failure(): void
    {
        Http::fake(['*' => Http::response(['not_a_token' => true])]);

        try {
            $this->metaAdapter()->exchangeCode('an-auth-code');
            $this->fail('The exchange should have thrown.');
        } catch (ProviderUnavailable $exception) {
            $this->assertFalse($exception->retryable);
        }
    }

    #[Test]
    public function a_token_meta_calls_invalid_fails_verification(): void
    {
        Http::fake(['*' => Http::response(['data' => ['is_valid' => false]])]);

        $connection = ProviderConnection::factory()->create();

        $this->assertFalse($this->metaAdapter()->verifyConnection($connection));
    }

    #[Test]
    public function a_valid_token_without_ads_management_fails_verification(): void
    {
        // A client can revoke one permission and leave the token valid. That
        // connection authenticates fine and cannot publish anything.
        Http::fake(['*' => Http::response([
            'data' => ['is_valid' => true, 'scopes' => ['public_profile', 'ads_read']],
        ])]);

        $connection = ProviderConnection::factory()->create();

        $this->assertFalse($this->metaAdapter()->verifyConnection($connection));
    }

    #[Test]
    public function a_valid_token_with_ads_management_passes_verification(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['is_valid' => true, 'scopes' => ['ads_management', 'ads_read']],
        ])]);

        $connection = ProviderConnection::factory()->create();

        $this->assertTrue($this->metaAdapter()->verifyConnection($connection));
    }

    #[Test]
    public function a_transient_failure_during_verification_is_raised_not_read_as_invalid(): void
    {
        Http::fake(['*' => Http::response($this->metaError(4, 'Rate limited'), 400)]);

        $connection = ProviderConnection::factory()->create();

        // Meta being briefly unavailable says nothing about the grant, and
        // treating it as revoked would disconnect a working client.
        $this->expectException(ProviderUnavailable::class);

        $this->metaAdapter()->verifyConnection($connection);
    }

    #[Test]
    public function a_revoked_connection_holding_no_token_fails_without_a_call(): void
    {
        Http::fake();

        $connection = ProviderConnection::factory()->revoked()->create();

        $this->assertFalse($this->metaAdapter()->verifyConnection($connection));

        Http::assertNothingSent();
    }

    #[Test]
    public function discovery_returns_the_assets_the_grant_actually_covers(): void
    {
        $this->fakeDiscovery();

        $assets = $this->metaAdapter()->discoverAssets(ProviderConnection::factory()->create());

        $types = array_map(static fn ($asset) => $asset->type, $assets);

        $this->assertContains(AssetType::MetaAdAccount, $types);
        $this->assertContains(AssetType::FacebookPage, $types);
        $this->assertContains(AssetType::InstagramAccount, $types);
    }

    #[Test]
    public function an_ad_account_id_comes_back_in_the_form_every_other_endpoint_wants(): void
    {
        $this->fakeDiscovery();

        $assets = $this->metaAdapter()->discoverAssets(ProviderConnection::factory()->create());

        $account = collect($assets)->firstWhere('type', AssetType::MetaAdAccount);

        // Meta returns a bare account_id but expects `act_`-prefixed ids
        // everywhere else, so discovery normalises it once.
        $this->assertSame('act_112233', $account->externalId);
        $this->assertSame('BDT', $account->currency);
    }

    #[Test]
    public function one_edge_failing_does_not_lose_the_assets_from_the_others(): void
    {
        Http::fake([
            '*me/adaccounts*' => Http::response([
                'data' => [[
                    'account_id' => '112233',
                    'name' => 'Client Ad Account',
                    'currency' => 'BDT',
                    'account_status' => 1,
                ]],
            ]),
            // The client never granted pages access.
            '*me/accounts*' => Http::response($this->metaError(200, 'Permissions error'), 403),
            '*owned_pixels*' => Http::response(['data' => []]),
        ]);

        $assets = $this->metaAdapter()->discoverAssets(ProviderConnection::factory()->create());

        // A client whose ad accounts we can read should still get them.
        $this->assertCount(1, $assets);
        $this->assertSame(AssetType::MetaAdAccount, $assets[0]->type);
    }

    #[Test]
    public function a_disabled_ad_account_is_reported_as_disabled_not_smoothed_over(): void
    {
        Http::fake(['*' => Http::response([
            'account_status' => 2,
            'disable_reason' => 1,
            'currency' => 'BDT',
        ])]);

        $state = $this->metaAdapter()->accountState('act_112233');

        $this->assertSame('DISABLED', $state->status);
        $this->assertNotNull($state->disapprovalReason);
        $this->assertStringContainsString('policy', (string) $state->disapprovalReason);
    }

    #[Test]
    public function an_active_account_with_no_funding_source_is_flagged_before_it_fails(): void
    {
        Http::fake(['*' => Http::response([
            'account_status' => 1,
            'currency' => 'BDT',
        ])]);

        $state = $this->metaAdapter()->accountState('act_112233');

        // Meta would refuse the first ad; better to know now.
        $this->assertSame('PAYMENT_METHOD_MISSING', $state->billingStatus);
    }

    #[Test]
    public function spend_that_meta_does_not_report_stays_null_rather_than_zero(): void
    {
        Http::fake(['*' => Http::response(['account_status' => 1, 'currency' => 'BDT'])]);

        $state = $this->metaAdapter()->accountState('act_112233');

        // Zero would read as "idle" and hand the account straight back out.
        $this->assertNull($state->spentTodayMinor);
        $this->assertNull($state->spentThisMonthMinor);
    }

    #[Test]
    public function an_account_id_without_the_prefix_is_normalised_before_the_call(): void
    {
        Http::fake(['*' => Http::response(['account_status' => 1, 'currency' => 'BDT'])]);

        $this->metaAdapter()->accountState('112233');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/act_112233'));
    }

    private function fakeDiscovery(): void
    {
        Http::fake([
            '*me/adaccounts*' => Http::response([
                'data' => [[
                    'account_id' => '112233',
                    'name' => 'Client Ad Account',
                    'currency' => 'BDT',
                    'timezone_name' => 'Asia/Dhaka',
                    'account_status' => 1,
                ]],
            ]),
            '*me/accounts*' => Http::response([
                'data' => [[
                    'id' => '556677',
                    'name' => 'Client Page',
                    'category' => 'Retail',
                    'tasks' => ['ADVERTISE', 'MANAGE'],
                    'connected_instagram_account' => ['id' => '778899', 'username' => 'clienthandle'],
                ]],
            ]),
            '*owned_pixels*' => Http::response(['data' => [['id' => '445566', 'name' => 'Client Pixel']]]),
        ]);
    }
}
