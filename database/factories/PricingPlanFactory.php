<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Billing\Enums\FeeType;
use App\Domains\Billing\Enums\PricingCalculation;
use App\Domains\Billing\Enums\PricingScope;
use App\Domains\Billing\Models\PricingPlan;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PricingPlan>
 */
class PricingPlanFactory extends Factory
{
    protected $model = PricingPlan::class;

    /**
     * @return array<model-property<PricingPlan>, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Plan '.$this->faker->unique()->word(),
            'scope' => PricingScope::Platform,
            'tenant_id' => null,
            'organization_id' => null,
            'currency' => 'BDT',
            'is_active' => true,
            'is_default' => false,
        ];
    }

    public function platformDefault(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Platform standard',
            'scope' => PricingScope::Platform,
            'is_default' => true,
        ]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (): array => [
            'scope' => PricingScope::Tenant,
            'tenant_id' => $tenant->getKey(),
            'organization_id' => null,
            'is_default' => false,
        ]);
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => [
            'scope' => PricingScope::Organization,
            'tenant_id' => $organization->tenant_id,
            'organization_id' => $organization->getKey(),
            'is_default' => false,
            'currency' => $organization->default_currency,
        ]);
    }

    /**
     * Attaches a percentage platform fee, matching how a real plan is built.
     */
    public function withPlatformFee(string $percentage = '7.5000'): static
    {
        return $this->afterCreating(function (PricingPlan $plan) use ($percentage): void {
            $plan->rules()->create([
                'fee_type' => FeeType::PlatformFee,
                'calculation' => PricingCalculation::Percentage,
                'percentage' => $percentage,
                'priority' => 10,
                'is_active' => true,
            ]);
        });
    }

    public function withTax(string $percentage = '15.0000'): static
    {
        return $this->afterCreating(function (PricingPlan $plan) use ($percentage): void {
            $plan->rules()->create([
                'fee_type' => FeeType::Tax,
                'calculation' => PricingCalculation::Percentage,
                'percentage' => $percentage,
                'priority' => 90,
                'is_active' => true,
            ]);
        });
    }
}
