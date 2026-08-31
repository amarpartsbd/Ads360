<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Campaign\Enums\AdSetStatus;
use App\Domains\Campaign\Enums\BidStrategy;
use App\Domains\Campaign\Models\AdSet;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Campaign\Values\Targeting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdSet>
 */
class AdSetFactory extends Factory
{
    protected $model = AdSet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'name' => $this->faker->words(2, true).' audience',
            'status' => AdSetStatus::Draft,
            'targeting' => Targeting::fromArray([
                'countries' => ['BD'],
                'minimum_age' => 25,
                'maximum_age' => 45,
            ])->toArray(),
            'bid_strategy' => BidStrategy::LowestCost,
        ];
    }

    /** Inherits tenant, organization and campaign together, so none drift. */
    public function forCampaign(Campaign $campaign): static
    {
        return $this->state(fn (): array => [
            'campaign_id' => $campaign->getKey(),
            'organization_id' => $campaign->organization_id,
            'tenant_id' => $campaign->tenant_id,
        ]);
    }

    public function targeting(Targeting $targeting): static
    {
        return $this->state(fn (): array => ['targeting' => $targeting->toArray()]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => AdSetStatus::Active,
            'provider_ad_set_id' => 'mock-adset-'.$this->faker->unique()->numerify('##########'),
            'published_at' => now(),
        ]);
    }
}
