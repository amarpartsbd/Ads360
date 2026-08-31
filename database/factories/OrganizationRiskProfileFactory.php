<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Client\Enums\RiskLevel;
use App\Domains\Client\Models\OrganizationRiskProfile;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationRiskProfile>
 */
class OrganizationRiskProfileFactory extends Factory
{
    protected $model = OrganizationRiskProfile::class;

    /**
     * @return array<model-property<OrganizationRiskProfile>, mixed>
     */
    public function definition(): array
    {
        return [
            'score' => 0,
            'level' => RiskLevel::Low,
            'factors' => [],
            'assessed_at' => now(),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization->getKey(),
            'tenant_id' => $organization->tenant_id,
        ]);
    }

    /**
     * A profile at a given score, with the band the database will accept for
     * it. Setting one without the other produces a row the check constraint
     * rejects, which is the point of the constraint.
     */
    public function scored(int $score): static
    {
        return $this->state(fn (): array => [
            'score' => $score,
            'level' => RiskLevel::forScore($score),
        ]);
    }
}
