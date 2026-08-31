<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Analytics\Models\CampaignDailyMetric;
use App\Domains\Campaign\Models\Campaign;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A month of plausible daily figures for development, so the analytics screens
 * are not empty on a fresh install.
 *
 * Deliberately uneven, and with a couple of days missing. A flat series would
 * make the chart look right when it is not: the gaps are what exercise the
 * "not reported yet" path, and a chart that joined across them would be
 * telling a lie nobody would notice on smooth data.
 */
class AnalyticsSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $campaigns = Campaign::query()->withoutGlobalScopes()->limit(5)->get();

        if ($campaigns->isEmpty()) {
            return;
        }

        foreach ($campaigns as $campaign) {
            $this->seedCampaign($campaign);
        }
    }

    private function seedCampaign(Campaign $campaign): void
    {
        $today = Carbon::now()->startOfDay();

        for ($daysAgo = 30; $daysAgo >= 1; $daysAgo--) {
            // Two missing days, so the chart's gap handling is visible.
            if (in_array($daysAgo, [17, 16], true)) {
                continue;
            }

            $date = $today->copy()->subDays($daysAgo);

            // A weekday-shaped curve rather than a straight line.
            $weekendFactor = $date->isWeekend() ? 0.6 : 1.0;
            $spend = (int) (random_int(18_000, 46_000) * $weekendFactor);
            $impressions = (int) ($spend * random_int(20, 32) / 10);
            $clicks = (int) ($impressions * random_int(90, 220) / 10_000);
            $conversions = (int) ($clicks * random_int(20, 70) / 1000);

            CampaignDailyMetric::query()->updateOrCreate(
                [
                    'campaign_id' => $campaign->getKey(),
                    'ad_set_id' => null,
                    'ad_id' => null,
                    'metric_date' => $date->toDateString(),
                ],
                [
                    'tenant_id' => $campaign->tenant_id,
                    'organization_id' => $campaign->organization_id,
                    'provider' => $campaign->provider,
                    'currency' => $campaign->currency,
                    'spend' => $spend,
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'reach' => (int) ($impressions * 0.72),
                    'conversions' => $conversions,
                    'conversion_value' => intdiv($conversions * random_int(150_000, 400_000), 100),
                    'reported_at' => Carbon::now(),
                ],
            );
        }
    }
}
