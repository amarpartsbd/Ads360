<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Domains\Analytics\Models\CampaignDailyMetric;
use App\Domains\Assistant\Enums\RecommendationStatus;
use App\Domains\Assistant\Models\Recommendation;
use App\Domains\Assistant\Services\PerformanceInsights;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * Performance insights (spec §47).
 *
 * These are arithmetic over the client's own figures, and the tests treat them
 * as such: the same data always produces the same observation, and a difference
 * too small or too thinly evidenced to act on produces none at all. A platform
 * that flagged noise would train clients to ignore the observations that matter.
 */
final class PerformanceInsightsTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();

        $this->to = Carbon::today();
        $this->from = $this->to->copy()->subDays(29);
    }

    private function organization(): Organization
    {
        return $this->createWorkspace()['organization'];
    }

    /**
     * A campaign with a month of figures, described in the terms that matter:
     * what it spent, and what it got.
     */
    private function campaign(
        Organization $organization,
        string $name,
        int $spendMinor,
        int $conversions,
        int $clicks = 500,
    ): Campaign {
        $campaign = Campaign::factory()->forOrganization($organization)->create(['name' => $name]);

        $metric = new CampaignDailyMetric;
        $metric->tenant_id = $organization->tenant_id;
        $metric->forceFill([
            'organization_id' => $organization->getKey(),
            'campaign_id' => $campaign->getKey(),
            'provider' => $campaign->provider->value,
            'metric_date' => $this->to->toDateString(),
            'currency' => $organization->default_currency,
            'spend' => $spendMinor,
            'impressions' => $clicks * 20,
            'clicks' => $clicks,
            'reach' => $clicks * 15,
            'conversions' => $conversions,
            'conversion_value' => 0,
            'reported_at' => now(),
        ])->save();

        return $campaign;
    }

    #[Test]
    public function it_names_the_campaign_costing_far_more_per_result(): void
    {
        $organization = $this->organization();

        // 180 taka a lead against 72 — §47's own example.
        $this->campaign($organization, 'Expensive', 18_000_00, 100);
        $this->campaign($organization, 'Efficient', 7_200_00, 100);

        $insights = app(PerformanceInsights::class)->compute($organization, $this->from, $this->to);

        $this->assertNotSame([], $insights);
        $this->assertStringContainsString('Expensive', $insights[0]['body']);
        $this->assertStringContainsString('Efficient', $insights[0]['body']);
    }

    #[Test]
    public function a_difference_too_small_to_act_on_is_not_reported(): void
    {
        $organization = $this->organization();

        // Ten percent apart: attribution windows, day of week, one good
        // creative. Reporting it would train clients to ignore the rest.
        $this->campaign($organization, 'Slightly worse', 11_000_00, 100);
        $this->campaign($organization, 'Slightly better', 10_000_00, 100);

        $insights = app(PerformanceInsights::class)->compute($organization, $this->from, $this->to);

        $this->assertSame([], $insights);
    }

    #[Test]
    public function too_few_conversions_to_compare_produces_nothing(): void
    {
        $organization = $this->organization();

        // A cost per conversion built on three conversions is an accident of
        // which three people happened to convert.
        $this->campaign($organization, 'Expensive', 18_000_00, 3);
        $this->campaign($organization, 'Efficient', 2_000_00, 3);

        $this->assertSame(
            [],
            app(PerformanceInsights::class)->compute($organization, $this->from, $this->to),
        );
    }

    #[Test]
    public function spend_with_no_conversions_is_reported_as_a_tracking_question(): void
    {
        $organization = $this->organization();

        $this->campaign($organization, 'Silent', 5_000_00, 0, clicks: 400);

        $insights = app(PerformanceInsights::class)->compute($organization, $this->from, $this->to);

        $this->assertNotSame([], $insights);

        /*
         * Carefully worded, and the wording is the point: conversions the
         * platform cannot see are still conversions, and telling a client
         * their campaign failed when their pixel is missing would be wrong
         * and expensive.
         */
        $this->assertStringContainsString('tracking', $insights[0]['body']);
    }

    #[Test]
    public function the_same_figures_always_produce_the_same_observation(): void
    {
        $organization = $this->organization();

        $this->campaign($organization, 'Expensive', 18_000_00, 100);
        $this->campaign($organization, 'Efficient', 7_200_00, 100);

        $service = app(PerformanceInsights::class);

        $this->assertEquals(
            $service->compute($organization, $this->from, $this->to),
            $service->compute($organization, $this->from, $this->to),
        );
    }

    #[Test]
    public function an_organization_with_no_figures_is_told_nothing(): void
    {
        $this->assertSame(
            [],
            app(PerformanceInsights::class)->compute($this->organization(), $this->from, $this->to),
        );
    }

    #[Test]
    public function stored_insights_are_marked_as_arithmetic_not_a_model(): void
    {
        $organization = $this->organization();

        $this->campaign($organization, 'Expensive', 18_000_00, 100);
        $this->campaign($organization, 'Efficient', 7_200_00, 100);

        $stored = app(PerformanceInsights::class)->refresh($organization, $this->from, $this->to);

        $this->assertNotSame([], $stored);
        $this->assertTrue($stored[0]->isDeterministic());
        $this->assertSame('performance-insights', $stored[0]->source_model);
    }

    #[Test]
    public function refreshing_expires_the_observations_it_replaces(): void
    {
        $organization = $this->organization();

        $this->campaign($organization, 'Expensive', 18_000_00, 100);
        $this->campaign($organization, 'Efficient', 7_200_00, 100);

        $service = app(PerformanceInsights::class);

        $first = $service->refresh($organization, $this->from, $this->to);
        $service->refresh($organization, $this->from, $this->to);

        /*
         * An observation about last month's spend shown beside this month's is
         * worse than none: the client cannot tell which window it is about.
         */
        $this->assertSame(RecommendationStatus::Expired, $first[0]->fresh()->status);

        $this->assertSame(
            1,
            Recommendation::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $organization->getKey())
                ->where('status', RecommendationStatus::Offered->value)
                ->count(),
        );
    }

    #[Test]
    public function campaigns_in_different_currencies_are_never_ranked_against_each_other(): void
    {
        $organization = $this->organization();

        // Two comparable campaigns, so there genuinely is an insight to make.
        $this->campaign($organization, 'Taka expensive', 18_000_00, 100);
        $this->campaign($organization, 'Taka efficient', 7_200_00, 100);

        // The same spend expressed in a currency worth roughly a hundred times
        // more. Ranked naively, this would look dramatically more efficient.
        $cheap = Campaign::factory()->forOrganization($organization)->create(['name' => 'Dollar campaign']);

        $metric = new CampaignDailyMetric;
        $metric->tenant_id = $organization->tenant_id;
        $metric->forceFill([
            'organization_id' => $organization->getKey(),
            'campaign_id' => $cheap->getKey(),
            'provider' => $cheap->provider->value,
            'metric_date' => $this->to->toDateString(),
            'currency' => 'USD',
            'spend' => 150_00,
            'impressions' => 10_000,
            'clicks' => 500,
            'reach' => 8_000,
            'conversions' => 100,
            'conversion_value' => 0,
            'reported_at' => now(),
        ])->save();

        $insights = app(PerformanceInsights::class)->compute($organization, $this->from, $this->to);

        $this->assertNotSame([], $insights, 'The two taka campaigns should have produced an insight.');

        $bodies = implode(' ', array_column($insights, 'body'));

        // The comparison happens, and stays inside one currency.
        $this->assertStringContainsString('Taka expensive', $bodies);
        $this->assertStringContainsString('Taka efficient', $bodies);

        /*
         * A rate this service invented would be a recommendation built on a
         * number nobody chose (Rule 8, §35), so the dollar campaign is never
         * ranked against them however efficient it looks.
         */
        $this->assertStringNotContainsString('Dollar campaign', $bodies);
    }
}
