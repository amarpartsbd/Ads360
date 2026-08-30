<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising;

use App\Domains\Advertising\Enums\ConnectionStatus;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Providers\MockAdvertisingProvider;
use App\Domains\Advertising\Services\ProviderManager;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Integration\Enums\AssetStatus;
use App\Domains\Integration\Models\OAuthState;
use App\Domains\Integration\Models\ProviderAsset;
use App\Domains\Integration\Models\ProviderConnection;
use App\Domains\Tenant\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * The OAuth round trip and the connections it produces (spec §16).
 */
final class ProviderConnectionTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    #[Test]
    public function starting_a_flow_issues_a_state_whose_plaintext_is_never_stored(): void
    {
        $workspace = $this->createWorkspace();

        $response = $this->actingAs($workspace['user'])
            ->get(route('client.assets.oauth.start', ['provider' => 'meta']));

        $response->assertRedirect();

        $state = OAuthState::query()->firstOrFail();
        $target = $response->headers->get('Location');

        parse_str((string) parse_url((string) $target, PHP_URL_QUERY), $query);

        $this->assertNotEmpty($query['state']);
        // Only the hash is kept: a stolen row cannot be replayed as a callback.
        $this->assertNotSame($query['state'], $state->state_hash);
        $this->assertSame(OAuthState::hash($query['state']), $state->state_hash);
    }

    #[Test]
    public function a_completed_flow_stores_an_encrypted_connection(): void
    {
        $workspace = $this->createWorkspace();

        $this->completeFlow($workspace['user']);

        $connection = ProviderConnection::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(ConnectionStatus::Connected, $connection->status);
        $this->assertSame($workspace['organization']->getKey(), $connection->organization_id);
        $this->assertSame($workspace['tenant']->getKey(), $connection->tenant_id);

        // The column holds ciphertext; the accessor decrypts it.
        $raw = (string) \Illuminate\Support\Facades\DB::table('provider_connections')
            ->where('id', $connection->getKey())
            ->value('access_token_encrypted');

        $this->assertNotSame($connection->accessToken(), $raw);
        $this->assertStringNotContainsString('mock-access-', $raw);
    }

    #[Test]
    public function a_connection_never_reaches_a_response(): void
    {
        $workspace = $this->createWorkspace();
        $this->completeFlow($workspace['user']);

        $response = $this->actingAs($workspace['user'])->get(route('client.assets.index'));

        $response->assertOk();

        $body = $response->getContent();

        $this->assertStringNotContainsString('access_token', (string) $body);
        $this->assertStringNotContainsString('mock-access-', (string) $body);
        $this->assertStringNotContainsString('mock-refresh-', (string) $body);
    }

    #[Test]
    public function serialising_a_connection_carries_no_token(): void
    {
        $connection = ProviderConnection::factory()->create();

        $array = $connection->toArray();

        $this->assertArrayNotHasKey('access_token_encrypted', $array);
        $this->assertArrayNotHasKey('refresh_token_encrypted', $array);
        $this->assertArrayNotHasKey('external_user_id', $array);
    }

    #[Test]
    public function a_state_cannot_be_redeemed_twice(): void
    {
        $workspace = $this->createWorkspace();
        $state = $this->issueState($workspace['user']);

        $this->actingAs($workspace['user'])->get($this->callbackUrl($state))->assertRedirect();

        $this->actingAs($workspace['user'])
            ->get($this->callbackUrl($state))
            ->assertSessionHas('error');

        $this->assertSame(1, ProviderConnection::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_state_issued_for_one_user_cannot_be_finished_by_another(): void
    {
        $workspace = $this->createWorkspace();
        $state = $this->issueState($workspace['user']);

        $other = $this->createWorkspace('client-owner', $workspace['tenant']);

        $this->actingAs($other['user'])
            ->get($this->callbackUrl($state))
            ->assertSessionHas('error');

        $this->assertSame(0, ProviderConnection::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function an_expired_state_is_refused(): void
    {
        $workspace = $this->createWorkspace();
        $state = $this->issueState($workspace['user']);

        OAuthState::query()->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->actingAs($workspace['user'])
            ->get($this->callbackUrl($state))
            ->assertSessionHas('error');

        $this->assertSame(0, ProviderConnection::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_rejected_state_is_recorded_without_the_state_itself(): void
    {
        $workspace = $this->createWorkspace();

        $this->actingAs($workspace['user'])
            ->get($this->callbackUrl('forged-state-value'))
            ->assertSessionHas('error');

        $entry = AuditLog::query()->where('action', 'integration.oauth.state_rejected')->firstOrFail();

        $this->assertStringNotContainsString('forged-state-value', json_encode($entry->context, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function a_client_who_declines_at_the_provider_gets_a_plain_message(): void
    {
        $workspace = $this->createWorkspace();
        $state = $this->issueState($workspace['user']);

        $this->actingAs($workspace['user'])
            ->get(route('client.assets.oauth.callback', [
                'provider' => 'meta',
                'error' => 'access_denied',
                'state' => $state,
            ]))
            ->assertRedirect(route('client.assets.index'))
            ->assertSessionHas('error');
    }

    #[Test]
    public function a_provider_failure_during_exchange_is_reported_not_thrown(): void
    {
        $workspace = $this->createWorkspace();
        $state = $this->issueState($workspace['user']);

        $this->provider()->willFail(
            'exchangeCode',
            ProviderUnavailable::transient(Provider::Meta, 'gateway timeout'),
        );

        $this->actingAs($workspace['user'])
            ->get($this->callbackUrl($state))
            ->assertRedirect(route('client.assets.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, ProviderConnection::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function completing_a_flow_discovers_the_connected_assets(): void
    {
        $workspace = $this->createWorkspace();

        $this->completeFlow($workspace['user']);

        $this->assertGreaterThan(0, ProviderAsset::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function disconnecting_clears_the_credentials_but_keeps_the_record(): void
    {
        $workspace = $this->createWorkspace();
        $this->completeFlow($workspace['user']);

        $connection = ProviderConnection::query()->withoutGlobalScopes()->firstOrFail();

        $this->actingAs($workspace['user'])
            ->delete(route('client.assets.connections.disconnect', $connection))
            ->assertRedirect();

        $connection->refresh();

        $this->assertSame(ConnectionStatus::Revoked, $connection->status);
        $this->assertNotNull($connection->revoked_at);
        $this->assertFalse($connection->hasCredentials());
        $this->assertNull($connection->refreshToken());

        // The assets stay too, marked as no longer usable rather than deleted.
        $this->assertSame(
            0,
            ProviderAsset::query()->withoutGlobalScopes()->where('status', AssetStatus::Available)->count(),
        );
        $this->assertGreaterThan(0, ProviderAsset::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_client_cannot_reach_another_organizations_connection(): void
    {
        $first = $this->createWorkspace();
        $this->completeFlow($first['user']);
        $connection = ProviderConnection::query()->withoutGlobalScopes()->firstOrFail();

        $intruder = $this->createWorkspace('client-owner', Tenant::factory()->create());

        $this->actingAs($intruder['user'])
            ->delete(route('client.assets.connections.disconnect', $connection))
            ->assertForbidden();

        $this->assertSame(ConnectionStatus::Connected, $connection->fresh()->status);
    }

    #[Test]
    public function platform_staff_may_not_authorise_on_a_clients_behalf(): void
    {
        $this->createWorkspace();
        $operator = $this->createPlatformUser();

        $this->actingAs($operator)
            ->get(route('client.assets.oauth.start', ['provider' => 'meta']))
            ->assertForbidden();
    }

    #[Test]
    public function a_provider_without_an_adapter_is_refused_rather_than_pretended_at(): void
    {
        $workspace = $this->createWorkspace();

        // TikTok has no adapter yet (spec §87): the answer is a plain refusal,
        // not a half-finished flow.
        $this->actingAs($workspace['user'])
            ->get(route('client.assets.oauth.start', ['provider' => 'tiktok']))
            ->assertRedirect(route('client.assets.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, OAuthState::query()->count());
    }

    // ------------------------------------------------------------------

    private function completeFlow(object $user): void
    {
        $state = $this->issueState($user);

        $this->actingAs($user)->get($this->callbackUrl($state))->assertRedirect();
    }

    private function issueState(object $user): string
    {
        $response = $this->actingAs($user)
            ->get(route('client.assets.oauth.start', ['provider' => 'meta']));

        parse_str(
            (string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY),
            $query,
        );

        return (string) $query['state'];
    }

    private function callbackUrl(string $state): string
    {
        return route('client.assets.oauth.callback', [
            'provider' => 'meta',
            'code' => 'mock-authorization-code',
            'state' => $state,
        ]);
    }

    private function provider(): MockAdvertisingProvider
    {
        $adapter = app(ProviderManager::class)->for(Provider::Meta);

        $this->assertInstanceOf(MockAdvertisingProvider::class, $adapter);

        return $adapter;
    }
}
