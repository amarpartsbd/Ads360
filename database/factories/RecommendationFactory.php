<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Assistant\Enums\RecommendationKind;
use App\Domains\Assistant\Enums\RecommendationStatus;
use App\Domains\Assistant\Models\Recommendation;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recommendation>
 */
class RecommendationFactory extends Factory
{
    protected $model = Recommendation::class;

    /**
     * @return array<model-property<Recommendation>, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => RecommendationKind::Insight,
            'status' => RecommendationStatus::Offered,
            'headline' => 'A sample observation',
            'body' => 'Something worth looking at.',
            'payload' => [],
            // Provenance is never optional, even on a fixture (spec §46).
            'source_driver' => 'deterministic',
            'source_model' => 'performance-insights',
            'source_version' => '1',
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization->getKey(),
            'tenant_id' => $organization->tenant_id,
        ]);
    }

    /** One that came from a model rather than from arithmetic. */
    public function fromAssistant(): static
    {
        return $this->state(fn (): array => [
            'kind' => RecommendationKind::Copy,
            'source_driver' => 'mock',
            'source_model' => 'stub',
            'source_version' => '1',
        ]);
    }
}
