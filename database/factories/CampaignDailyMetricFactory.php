<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Analytics\Models\CampaignDailyMetric;
use App\Domains\Campaign\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<CampaignDailyMetric>
 */
class CampaignDailyMetricFactory extends Factory
{
    protected $model = CampaignDailyMetric::class;

    /**
     * @return array<model-property<CampaignDailyMetric>, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'provider' => Provider::Meta,
            'metric_date' => Carbon::now()->subDay()->toDateString(),
            'currency' => 'BDT',
            'spend' => 50_000,
            'impressions' => 12_000,
            'clicks' => 240,
            'reach' => 9_000,
            'conversions' => 12,
            'conversion_value' => 300_000,
            'reported_at' => Carbon::now(),
        ];
    }

    /** Inherits tenant, organization and currency together, so none drift. */
    public function forCampaign(Campaign $campaign): static
    {
        return $this->state(fn (): array => [
            'campaign_id' => $campaign->getKey(),
            'organization_id' => $campaign->organization_id,
            'tenant_id' => $campaign->tenant_id,
            'provider' => $campaign->provider,
            'currency' => $campaign->currency,
        ]);
    }

    public function on(string|Carbon $date): static
    {
        return $this->state(fn (): array => [
            'metric_date' => $date instanceof Carbon ? $date->toDateString() : $date,
        ]);
    }

    public function spent(int $minorUnits): static
    {
        return $this->state(fn (): array => ['spend' => $minorUnits]);
    }
}
