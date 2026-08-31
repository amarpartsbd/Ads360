<?php

declare(strict_types=1);

namespace Tests\Feature\Campaign;

use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Campaign\Models\Creative;
use App\Domains\Tenant\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * Who may see and change a campaign (spec §7, §21, §68).
 *
 * The negative assertions are the point. A campaign carries a client's budget
 * and their advertising copy; another tenant reaching it would be both a
 * financial and a commercial leak.
 */
final class CampaignAccessTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
        Queue::fake();
    }

    #[Test]
    public function a_client_sees_only_their_own_campaigns(): void
    {
        $mine = $this->createWorkspace();
        $theirs = $this->createWorkspace('client-owner', Tenant::factory()->create());

        Campaign::factory()->forOrganization($mine['organization'])->count(2)->create();
        Campaign::factory()->forOrganization($theirs['organization'])->count(3)->create();

        $response = $this->actingAs($mine['user'])->get(route('client.campaigns.index'));

        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertCount(2, $props['campaigns']['data']);
    }

    #[Test]
    public function a_client_cannot_open_another_tenants_campaign(): void
    {
        $mine = $this->createWorkspace();
        $theirs = $this->createWorkspace('client-owner', Tenant::factory()->create());

        $campaign = Campaign::factory()->forOrganization($theirs['organization'])->create();

        $this->actingAs($mine['user'])
            ->get(route('client.campaigns.show', $campaign))
            ->assertForbidden();
    }

    #[Test]
    public function a_client_cannot_submit_another_tenants_campaign(): void
    {
        $mine = $this->createWorkspace();
        $theirs = $this->createWorkspace('client-owner', Tenant::factory()->create());

        $campaign = Campaign::factory()->forOrganization($theirs['organization'])->create();

        $this->actingAs($mine['user'])
            ->post(route('client.campaigns.submit', $campaign))
            ->assertForbidden();
    }

    #[Test]
    public function a_client_cannot_reach_the_review_queue(): void
    {
        $workspace = $this->createWorkspace();

        $this->actingAs($workspace['user'])
            ->get(route('admin.campaigns.index'))
            ->assertForbidden();
    }

    #[Test]
    public function a_client_cannot_approve_a_campaign(): void
    {
        $workspace = $this->createWorkspace();

        $campaign = Campaign::factory()
            ->forOrganization($workspace['organization'])
            ->create(['status' => CampaignStatus::PendingReview, 'submitted_at' => now()]);

        $this->actingAs($workspace['user'])
            ->post(route('admin.campaigns.approve', $campaign))
            ->assertForbidden();

        $this->assertSame(CampaignStatus::PendingReview, $campaign->fresh()->status);
    }

    #[Test]
    public function a_reviewer_cannot_approve_a_campaign_they_submitted(): void
    {
        $workspace = $this->createWorkspace();
        $reviewer = $this->createPlatformUser();

        $campaign = Campaign::factory()
            ->forOrganization($workspace['organization'])
            ->create([
                'status' => CampaignStatus::PendingReview,
                'submitted_at' => now(),
                'submitted_by' => $reviewer->getKey(),
            ]);

        $this->actingAs($reviewer)
            ->post(route('admin.campaigns.approve', $campaign))
            ->assertForbidden();
    }

    #[Test]
    public function a_client_cannot_download_another_tenants_creative(): void
    {
        Storage::fake('creatives');

        $mine = $this->createWorkspace();
        $theirs = $this->createWorkspace('client-owner', Tenant::factory()->create());

        $creative = Creative::factory()
            ->forOrganization($theirs['organization'])
            ->withStoredBytes()
            ->create();

        $this->actingAs($mine['user'])
            ->get(route('client.creatives.download', $creative))
            ->assertForbidden();
    }

    #[Test]
    public function a_creatives_storage_path_never_reaches_a_response(): void
    {
        Storage::fake('creatives');

        $workspace = $this->createWorkspace();

        $creative = Creative::factory()
            ->forOrganization($workspace['organization'])
            ->withStoredBytes()
            ->create();

        $response = $this->actingAs($workspace['user'])->get(route('client.creatives.index'));

        $response->assertOk();
        // The path is a location on a private disk; publishing it would invite
        // someone to try fetching it directly.
        $response->assertDontSee($creative->storage_path);
        $this->assertArrayNotHasKey('storage_path', $creative->toArray());
    }

    #[Test]
    public function a_creative_in_use_by_an_ad_cannot_be_deleted(): void
    {
        Storage::fake('creatives');

        $workspace = $this->createWorkspace();

        $creative = Creative::factory()
            ->forOrganization($workspace['organization'])
            ->withStoredBytes()
            ->create();

        $campaign = Campaign::factory()->forOrganization($workspace['organization'])->create();
        $adSet = \App\Domains\Campaign\Models\AdSet::factory()->forCampaign($campaign)->create();
        \App\Domains\Campaign\Models\Ad::factory()
            ->forAdSet($adSet)
            ->create(['creative_id' => $creative->getKey()]);

        $this->actingAs($workspace['user'])
            ->delete(route('client.creatives.destroy', $creative))
            ->assertForbidden();

        $this->assertNotNull($creative->fresh());
    }

    #[Test]
    public function platform_staff_cannot_create_a_campaign_for_a_client(): void
    {
        $this->createWorkspace();

        $this->actingAs($this->createPlatformUser())
            ->post(route('client.campaigns.store'), [
                'name' => 'Staff campaign',
                'provider' => 'META',
                'objective' => 'TRAFFIC',
                'budget_type' => 'LIFETIME',
                'budget_amount' => '100.00',
                'starts_at' => now()->addDay()->toDateTimeString(),
            ])
            ->assertForbidden();
    }
}
