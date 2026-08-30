<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Tenant\Models\Organization;
use App\Domains\Wallet\Enums\WalletStatus;
use App\Domains\Wallet\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'currency' => 'BDT',
            'status' => WalletStatus::Active,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization->getKey(),
            'tenant_id' => $organization->tenant_id,
            'currency' => $organization->default_currency,
        ]);
    }

    public function frozen(): static
    {
        return $this->state(fn (): array => [
            'status' => WalletStatus::Frozen,
            'status_reason' => 'Frozen in test fixture.',
        ]);
    }
}
