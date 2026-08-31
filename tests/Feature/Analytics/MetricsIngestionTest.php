<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domains\Advertising\DTOs\DailyInsightRow;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Providers\MockAdvertisingProvider;
use App\Domains\Advertising\Services\ProviderManager;
use App\Domains\Analytics\Models\CampaignDailyMetric;
use App\Domains\Analytics\Services\MetricsIngestor;
use App\Domains\Campaign\Models\Campaign;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsCampaigns;
use Tests\TestCase;

/**
 * Metrics ingestion (spec §38, §87).
 *
 * The tests that matter here are about *restatement*: providers move spend and
 * conversions onto days they already reported, and a pipeline that appended
 * rather than replaced would show a client a total that grew every hour.
 */
final class MetricsIngestionTest extends TestCase
{
    use BuildsCampaigns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function a_days_figures_are_stored_in_minor_units(): void
    {
        $campaign = $this->publishedCampaign();

        $this->provider()->willReportDailyInsights([
            $this->row('-1 day', spend: 123_456, clicks: 890),
        ]);

        app(MetricsIngestor::class)->ingest($campaign);

        $metric = CampaignDailyMetric::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(123_456, $metric->spend);
        $this->assertSame(890, $metric->clicks);
        $this->assertSame('BDT', $metric->currency);
    }

    #[Test]
    public function re_ingesting_the_same_day_replaces_it_rather_than_adding_to_it(): void
    {
        $campaign = $this->publishedCampaign();

        $this->provider()->willReportDailyInsights([$this->row('-1 day', spend: 100_000)]);
        app(MetricsIngestor::class)->ingest($campaign);

        // The provider restates the same day upward, as it does once an
        // attribution window closes.
        $this->provider()->willReportDailyInsights([$this->row('-1 day', spend: 140_000)]);
        app(MetricsIngestor::class)->ingest($campaign);

        $this->assertSame(1, CampaignDailyMetric::query()->withoutGlobalScopes()->count());
        $this->assertSame(
            140_000,
            (int) CampaignDailyMetric::query()->withoutGlobalScopes()->sum('spend'),
        );
    }

    #[Test]
    public function ingesting_the_same_window_many_times_does_not_inflate_the_total(): void
    {
        $campaign = $this->publishedCampaign();

        $this->provider()->willReportDailyInsights([
            $this->row('-2 days', spend: 50_000),
            $this->row('-1 day', spend: 70_000),
        ]);

        foreach (range(1, 5) as $ignored) {
            app(MetricsIngestor::class)->ingest($campaign);
        }

        $this->assertSame(2, CampaignDailyMetric::query()->withoutGlobalScopes()->count());
        $this->assertSame(
            120_000,
            (int) CampaignDailyMetric::query()->withoutGlobalScopes()->sum('spend'),
        );
    }

    #[Test]
    public function a_restatement_that_moves_spend_between_days_keeps_the_total_right(): void
    {
        $campaign = $this->publishedCampaign();

        $this->provider()->willReportDailyInsights([
            $this->row('-2 days', spend: 100_000),
            $this->row('-1 day', spend: 0),
        ]);
        app(MetricsIngestor::class)->ingest($campaign);

        // A late conversion moves half the spend onto the other day.
        $this->provider()->willReportDailyInsights([
            $this->row('-2 days', spend: 50_000),
            $this->row('-1 day', spend: 50_000),
        ]);
        app(MetricsIngestor::class)->ingest($campaign);

        $this->assertSame(
            100_000,
            (int) CampaignDailyMetric::query()->withoutGlobalScopes()->sum('spend'),
        );
    }

    #[Test]
    public function a_day_the_provider_reports_nothing_for_is_not_stored_as_zeroes(): void
    {
        $campaign = $this->publishedCampaign();

        $this->provider()->willReportDailyInsights([
            new DailyInsightRow(date: new DateTimeImmutable('-1 day'), currency: 'BDT'),
        ]);

        app(MetricsIngestor::class)->ingest($campaign);

        // Zeroes would tell a client their campaign ran and achieved nothing
        // on a day the provider had simply not finished counting.
        $this->assertSame(0, CampaignDailyMetric::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_database_refuses_two_rows_for_one_campaign_day(): void
    {
        $campaign = $this->publishedCampaign();

        $this->provider()->willReportDailyInsights([$this->row('-1 day', spend: 10_000)]);
        app(MetricsIngestor::class)->ingest($campaign);

        $existing = CampaignDailyMetric::query()->withoutGlobalScopes()->firstOrFail();

        $this->expectException(QueryException::class);

        // Bypasses the service: the index has to hold on its own, because a
        // race can put two writers past any read-then-write.
        DB::table('campaign_daily_metrics')->insert([
            'tenant_id' => $existing->tenant_id,
            'organization_id' => $existing->organization_id,
            'campaign_id' => $existing->campaign_id,
            'provider' => Provider::Meta->value,
            'metric_date' => $existing->metric_date->toDateString(),
            'currency' => 'BDT',
            'spend' => 999,
            'reported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function the_database_refuses_a_negative_figure(): void
    {
        $campaign = $this->publishedCampaign();

        $this->expectException(QueryException::class);

        // A negative would quietly reduce a client's reported spend.
        DB::table('campaign_daily_metrics')->insert([
            'tenant_id' => $campaign->tenant_id,
            'organization_id' => $campaign->organization_id,
            'campaign_id' => $campaign->getKey(),
            'provider' => Provider::Meta->value,
            'metric_date' => now()->toDateString(),
            'currency' => 'BDT',
            'spend' => -1,
            'reported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function metrics_carry_the_campaigns_tenant_and_organization(): void
    {
        $campaign = $this->publishedCampaign();

        $this->provider()->willReportDailyInsights([$this->row('-1 day', spend: 10_000)]);
        app(MetricsIngestor::class)->ingest($campaign);

        $metric = CampaignDailyMetric::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame($campaign->tenant_id, $metric->tenant_id);
        $this->assertSame($campaign->organization_id, $metric->organization_id);
    }

    #[Test]
    public function an_unpublished_campaign_is_skipped_without_calling_the_provider(): void
    {
        $campaign = $this->submittedCampaign();

        $this->assertSame(0, app(MetricsIngestor::class)->ingest($campaign));
    }

    private function publishedCampaign(): Campaign
    {
        $campaign = $this->approvedCampaign();

        app(\App\Domains\Campaign\Services\CampaignPublisher::class)->publish($campaign->fresh());

        return $campaign->fresh();
    }

    private function row(string $modifier, int $spend, int $clicks = 0): DailyInsightRow
    {
        return new DailyInsightRow(
            date: new DateTimeImmutable(Carbon::now()->modify($modifier)->toDateString()),
            spendMinor: $spend,
            currency: 'BDT',
            impressions: 1000,
            clicks: $clicks,
        );
    }

    private function provider(): MockAdvertisingProvider
    {
        $adapter = app(ProviderManager::class)->for(Provider::Meta);

        $this->assertInstanceOf(MockAdvertisingProvider::class, $adapter);

        return $adapter;
    }
}
