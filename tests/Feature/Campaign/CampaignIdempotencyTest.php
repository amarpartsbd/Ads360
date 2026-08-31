<?php

declare(strict_types=1);

namespace Tests\Feature\Campaign;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Providers\MockAdvertisingProvider;
use App\Domains\Advertising\Services\ProviderManager;
use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Enums\PublicationOperation;
use App\Domains\Campaign\Enums\PublicationStatus;
use App\Domains\Campaign\Jobs\PublishCampaign;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Campaign\Models\CampaignPublication;
use App\Domains\Campaign\Services\CampaignPublisher;
use App\Domains\Campaign\Services\PublicationLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\BuildsCampaigns;
use Tests\TestCase;

/**
 * Campaign publishing idempotency — a §98 pre-deployment gate.
 *
 * The failure this guards against is concrete: a retried job creates a second
 * campaign at the provider, and the client's money is spent twice on the same
 * advertising. Every test here is a way that could happen.
 */
final class CampaignIdempotencyTest extends TestCase
{
    use BuildsCampaigns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Approval queues the publish job, and the sync driver would run it
        // before a test could arrange the provider. These tests drive the
        // publisher themselves; that the approval dispatches it is asserted in
        // CampaignWorkflowTest instead.
        Queue::fake();
    }

    #[Test]
    public function publishing_twice_creates_one_campaign_at_the_provider(): void
    {
        $campaign = $this->approvedCampaign();

        app(CampaignPublisher::class)->publish($campaign->fresh());
        $first = $campaign->fresh()->provider_campaign_id;

        app(CampaignPublisher::class)->publish($campaign->fresh());
        $second = $campaign->fresh()->provider_campaign_id;

        $this->assertNotNull($first);
        $this->assertSame($first, $second);

        // Three entities exist: the campaign, its audience and its ad. A
        // second publish must not have added a fourth.
        $this->assertSame(3, $this->provider()->creationCount());
    }

    #[Test]
    public function running_the_job_repeatedly_creates_one_campaign(): void
    {
        $campaign = $this->approvedCampaign();

        foreach (range(1, 4) as $ignored) {
            (new PublishCampaign($campaign->getKey()))->handle(
                app(CampaignPublisher::class),
                app(\App\Domains\Audit\Services\AuditRecorder::class),
            );
        }

        $this->assertSame(3, $this->provider()->creationCount());
        $this->assertSame(
            1,
            CampaignPublication::query()
                ->where('operation', PublicationOperation::CreateCampaign)
                ->where('status', PublicationStatus::Succeeded)
                ->count(),
        );
    }

    #[Test]
    public function a_second_success_for_the_same_creation_is_refused_by_the_database(): void
    {
        $campaign = $this->approvedCampaign();
        app(CampaignPublisher::class)->publish($campaign->fresh());

        $this->expectException(QueryException::class);

        // Bypasses every service: the index has to hold on its own, because a
        // race can put two writers past any application-level check.
        DB::table('campaign_publications')->insert([
            'tenant_id' => $campaign->tenant_id,
            'campaign_id' => $campaign->getKey(),
            'publishable_type' => Campaign::class,
            'publishable_id' => $campaign->getKey(),
            'provider' => Provider::Meta->value,
            'operation' => PublicationOperation::CreateCampaign->value,
            'idempotency_key' => 'a-different-key-entirely',
            'status' => PublicationStatus::Succeeded->value,
            'provider_reference' => 'duplicate-campaign',
            'attempts' => 1,
            'request_snapshot' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function an_idempotency_key_cannot_be_used_twice(): void
    {
        $campaign = $this->approvedCampaign();
        app(CampaignPublisher::class)->publish($campaign->fresh());

        $existing = CampaignPublication::query()->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('campaign_publications')->insert([
            'tenant_id' => $campaign->tenant_id,
            'campaign_id' => $campaign->getKey(),
            'publishable_type' => Campaign::class,
            'publishable_id' => $campaign->getKey(),
            'provider' => Provider::Meta->value,
            'operation' => PublicationOperation::Pause->value,
            // The same key, for a different operation. Still refused: a key
            // identifies one intent, not one entity.
            'idempotency_key' => $existing->idempotency_key,
            'status' => PublicationStatus::Pending->value,
            'attempts' => 1,
            'request_snapshot' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_succeeded_row_must_carry_the_providers_reference(): void
    {
        $campaign = $this->approvedCampaign();

        $this->expectException(QueryException::class);

        // A success with nothing to show for it would leave the platform
        // unable to find what it created.
        DB::table('campaign_publications')->insert([
            'tenant_id' => $campaign->tenant_id,
            'campaign_id' => $campaign->getKey(),
            'publishable_type' => Campaign::class,
            'publishable_id' => $campaign->getKey(),
            'provider' => Provider::Meta->value,
            'operation' => PublicationOperation::CreateCampaign->value,
            'idempotency_key' => 'no-reference-key',
            'status' => PublicationStatus::Succeeded->value,
            'provider_reference' => null,
            'attempts' => 1,
            'request_snapshot' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_retry_after_a_crash_reuses_the_key_rather_than_minting_a_new_one(): void
    {
        $campaign = $this->approvedCampaign();

        // Exactly the state a worker killed mid-request leaves behind: the
        // claim written, the provider possibly already acted, nothing settled.
        $publication = app(PublicationLedger::class)->claim(
            $campaign,
            $campaign,
            PublicationOperation::CreateCampaign,
        );

        $this->assertNotNull($publication);
        $original = $publication->idempotency_key;

        app(CampaignPublisher::class)->publish($campaign->fresh());

        $publication->refresh();

        $this->assertSame($original, $publication->idempotency_key);
        $this->assertSame(PublicationStatus::Succeeded, $publication->status);
        $this->assertSame(2, $publication->attempts);

        // One claim, one campaign — the resumed attempt did not create a
        // second one alongside it.
        $this->assertSame(
            1,
            CampaignPublication::query()
                ->where('operation', PublicationOperation::CreateCampaign)
                ->count(),
        );
    }

    #[Test]
    public function a_partly_published_campaign_resumes_rather_than_starting_over(): void
    {
        $campaign = $this->approvedCampaign();

        // The campaign lands, then the provider refuses the audience.
        $this->provider()->willFail(
            'createAdSet',
            ProviderUnavailable::transient(Provider::Meta, 'gateway timeout'),
        );

        try {
            app(CampaignPublisher::class)->publish($campaign->fresh());
        } catch (ProviderUnavailable) {
            // Expected.
        }

        $campaign->refresh();
        $campaignId = $campaign->provider_campaign_id;

        $this->assertNotNull($campaignId);
        $this->assertSame(1, $this->provider()->creationCount());

        // The provider recovers and the retry picks up where it stopped.
        $this->provider()->willSucceed('createAdSet');

        app(CampaignPublisher::class)->publish($campaign->fresh());

        $campaign->refresh();

        $this->assertSame($campaignId, $campaign->provider_campaign_id);
        $this->assertSame(3, $this->provider()->creationCount());
        $this->assertSame(CampaignStatus::Active, $campaign->status);
    }

    #[Test]
    public function a_failed_publish_keeps_the_budget_held_so_it_can_be_retried(): void
    {
        $campaign = $this->approvedCampaign();

        $this->provider()->willFail(
            'createCampaign',
            ProviderUnavailable::refused(Provider::Meta, 'account not permitted'),
        );

        (new PublishCampaign($campaign->getKey()))->handle(
            app(CampaignPublisher::class),
            app(\App\Domains\Audit\Services\AuditRecorder::class),
        );

        $campaign->refresh();

        $this->assertSame(CampaignStatus::Failed, $campaign->status);
        // Still resourced: releasing here would lose a client their held
        // budget to another campaign before anyone could retry.
        $this->assertNotNull($campaign->wallet_reservation_id);
        $this->assertNotNull($campaign->ad_account_id);
        $this->assertNotNull($campaign->last_error);
    }

    #[Test]
    public function a_stored_provider_error_code_never_reaches_the_campaign(): void
    {
        $campaign = $this->approvedCampaign();

        $this->provider()->willFail(
            'createCampaign',
            ProviderUnavailable::refused(Provider::Meta, 'OAuthException #190 invalid token'),
        );

        (new PublishCampaign($campaign->getKey()))->handle(
            app(CampaignPublisher::class),
            app(\App\Domains\Audit\Services\AuditRecorder::class),
        );

        $this->assertStringNotContainsString('OAuthException', (string) $campaign->fresh()->last_error);
    }

    #[Test]
    public function a_publication_record_cannot_be_deleted(): void
    {
        $campaign = $this->approvedCampaign();
        app(CampaignPublisher::class)->publish($campaign->fresh());

        $this->expectException(RuntimeException::class);

        CampaignPublication::query()->firstOrFail()->delete();
    }

    #[Test]
    public function two_provider_campaign_ids_cannot_collide(): void
    {
        $first = $this->approvedCampaign();
        app(CampaignPublisher::class)->publish($first->fresh());

        $second = $this->approvedCampaign();

        $this->expectException(QueryException::class);

        DB::table('campaigns')
            ->where('id', $second->getKey())
            ->update(['provider_campaign_id' => $first->fresh()->provider_campaign_id]);
    }

    private function provider(): MockAdvertisingProvider
    {
        $adapter = app(ProviderManager::class)->for(Provider::Meta);

        $this->assertInstanceOf(MockAdvertisingProvider::class, $adapter);

        return $adapter;
    }
}
