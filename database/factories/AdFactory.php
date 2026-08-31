<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Campaign\Enums\AdSetStatus;
use App\Domains\Campaign\Models\Ad;
use App\Domains\Campaign\Models\AdSet;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Campaign\Models\Creative;
use App\Domains\Integration\Models\ProviderAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ad>
 */
class AdFactory extends Factory
{
    protected $model = Ad::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ad_set_id' => AdSet::factory(),
            'name' => $this->faker->words(3, true),
            'status' => AdSetStatus::Draft,
            'headline' => $this->faker->sentence(4),
            'primary_text' => $this->faker->paragraph(),
            'description' => $this->faker->sentence(8),
            'call_to_action' => 'LEARN_MORE',
            'destination_url' => 'https://example.test/landing',
        ];
    }

    public function forAdSet(AdSet $adSet): static
    {
        return $this->state(fn (): array => [
            'ad_set_id' => $adSet->getKey(),
            'campaign_id' => $adSet->campaign_id,
            'organization_id' => $adSet->organization_id,
            'tenant_id' => $adSet->tenant_id,
        ]);
    }

    public function forCampaign(Campaign $campaign): static
    {
        return $this->state(fn (): array => [
            'campaign_id' => $campaign->getKey(),
            'organization_id' => $campaign->organization_id,
            'tenant_id' => $campaign->tenant_id,
        ]);
    }

    /** Everything a provider needs: a creative and an identity to run as. */
    public function complete(Creative $creative, ProviderAsset $identity): static
    {
        return $this->state(fn (): array => [
            'creative_id' => $creative->getKey(),
            'identity_asset_id' => $identity->getKey(),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => AdSetStatus::Active,
            'provider_ad_id' => 'mock-ad-'.$this->faker->unique()->numerify('##########'),
            'published_at' => now(),
        ]);
    }
}
