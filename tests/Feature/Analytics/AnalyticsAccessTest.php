<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domains\Analytics\Enums\ExportStatus;
use App\Domains\Analytics\Enums\ReportType;
use App\Domains\Analytics\Models\CampaignDailyMetric;
use App\Domains\Analytics\Models\ReportExport;
use App\Domains\Analytics\Services\ReportWriter;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * Who can see which figures, and which files (spec §7, §39, §68).
 *
 * A report is a file of one organization's spend and conversions. Every
 * negative assertion here is a way it could reach somebody else.
 */
final class AnalyticsAccessTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
        Storage::fake('reports');
        Queue::fake();
    }

    #[Test]
    public function a_client_sees_only_their_own_figures(): void
    {
        $mine = $this->createWorkspace();
        $theirs = $this->createWorkspace('client-owner', Tenant::factory()->create());

        $this->metricFor($mine['organization'], 100_000);
        $this->metricFor($theirs['organization'], 999_000);

        $response = $this->actingAs($mine['user'])->get(route('client.analytics.index'));

        $response->assertOk();

        $totals = $response->viewData('page')['props']['totals'];

        // The other tenant's spend must not be anywhere in the answer.
        $this->assertSame(100_000, $totals['spendMinor']);
    }

    #[Test]
    public function a_client_cannot_download_another_tenants_report(): void
    {
        $mine = $this->createWorkspace();
        $theirs = $this->createWorkspace('client-owner', Tenant::factory()->create());

        $export = $this->readyExport($theirs['organization']);

        $this->actingAs($mine['user'])
            ->get(route('client.analytics.exports.download', $export))
            ->assertForbidden();
    }

    #[Test]
    public function a_reports_storage_path_never_reaches_a_response(): void
    {
        $workspace = $this->createWorkspace();
        $export = $this->readyExport($workspace['organization']);

        $response = $this->actingAs($workspace['user'])->get(route('client.analytics.index'));

        $response->assertOk();
        $response->assertDontSee($export->storage_path);
        $this->assertArrayNotHasKey('storage_path', $export->toArray());
    }

    #[Test]
    public function an_expired_report_is_not_downloadable_even_before_the_sweep_runs(): void
    {
        $workspace = $this->createWorkspace();

        $export = $this->readyExport($workspace['organization']);
        $export->forceFill(['expires_at' => now()->subHour()])->save();

        // The sweep runs on a schedule; a file can be past its date before it
        // next runs, so the check is on the row rather than on the sweep.
        $this->actingAs($workspace['user'])
            ->get(route('client.analytics.exports.download', $export))
            ->assertNotFound();
    }

    #[Test]
    public function a_client_cannot_reach_the_reconciliation_queue(): void
    {
        $workspace = $this->createWorkspace();

        $this->actingAs($workspace['user'])
            ->get(route('admin.analytics.reconciliation'))
            ->assertForbidden();
    }

    #[Test]
    public function a_client_cannot_reach_platform_analytics(): void
    {
        $workspace = $this->createWorkspace();

        $this->actingAs($workspace['user'])
            ->get(route('admin.analytics.overview'))
            ->assertForbidden();
    }

    #[Test]
    public function requesting_an_export_queues_it_rather_than_generating_it_inline(): void
    {
        $workspace = $this->createWorkspace();

        $this->actingAs($workspace['user'])
            ->post(route('client.analytics.exports.store'), [
                'type' => ReportType::CampaignPerformance->value,
                'from' => now()->subDays(7)->toDateString(),
                'to' => now()->toDateString(),
            ])
            ->assertRedirect();

        $export = ReportExport::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(ExportStatus::Queued, $export->status);
        Queue::assertPushed(\App\Domains\Analytics\Jobs\GenerateReportExport::class);
    }

    #[Test]
    public function an_export_window_wider_than_the_maximum_is_refused(): void
    {
        config()->set('platform.reporting.max_export_days', 30);

        $workspace = $this->createWorkspace();

        $this->actingAs($workspace['user'])
            ->post(route('client.analytics.exports.store'), [
                'type' => ReportType::DailyPerformance->value,
                'from' => now()->subYears(3)->toDateString(),
                'to' => now()->toDateString(),
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, ReportExport::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function pressing_the_button_twice_does_not_queue_two_exports(): void
    {
        $workspace = $this->createWorkspace();

        $payload = [
            'type' => ReportType::CampaignPerformance->value,
            'from' => now()->subDays(7)->toDateString(),
            'to' => now()->toDateString(),
        ];

        $this->actingAs($workspace['user'])->post(route('client.analytics.exports.store'), $payload);
        $this->actingAs($workspace['user'])->post(route('client.analytics.exports.store'), $payload);

        $this->assertSame(1, ReportExport::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_campaign_name_that_looks_like_a_formula_is_neutralised_in_the_export(): void
    {
        $workspace = $this->createWorkspace();

        $campaign = Campaign::factory()
            ->forOrganization($workspace['organization'])
            // A name a client could genuinely type. A spreadsheet opening the
            // CSV would otherwise evaluate it.
            ->create(['name' => '=HYPERLINK("http://evil.test","click")']);

        CampaignDailyMetric::factory()->forCampaign($campaign)->on(now()->subDay())->create();

        $export = $this->queuedExport($workspace['organization']);

        $result = app(ReportWriter::class)->write($export, $workspace['organization']);

        $contents = Storage::disk('reports')->get($result['path']);

        $this->assertStringContainsString("'=HYPERLINK", (string) $contents);
        $this->assertStringNotContainsString("\n=HYPERLINK", (string) $contents);
    }

    private function metricFor(Organization $organization, int $spend): void
    {
        $campaign = Campaign::factory()->forOrganization($organization)->create();

        CampaignDailyMetric::factory()
            ->forCampaign($campaign)
            ->on(now()->subDay())
            ->spent($spend)
            ->create();
    }

    /**
     * The tenant is stamped rather than mass-assigned, mirroring the action:
     * it is deliberately not fillable, so no request can choose it.
     */
    private function queuedExport(Organization $organization): ReportExport
    {
        $export = new ReportExport([
            'organization_id' => $organization->getKey(),
            'type' => ReportType::CampaignPerformance,
            'status' => ExportStatus::Queued,
            'filters' => [],
            'period_start' => now()->subDays(7)->toDateString(),
            'period_end' => now()->toDateString(),
        ]);

        $export->tenant_id = $organization->tenant_id;
        $export->save();

        return $export;
    }

    private function readyExport(Organization $organization): ReportExport
    {
        $export = $this->queuedExport($organization);

        Storage::disk('reports')->put('fixture/report.csv', "Campaign\nTest\n");

        $export->forceFill([
            'status' => ExportStatus::Ready,
            'storage_path' => 'fixture/report.csv',
            'row_count' => 1,
            'byte_size' => 16,
            'completed_at' => now(),
            'expires_at' => now()->addDays(7),
        ])->save();

        return $export;
    }
}
