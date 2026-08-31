<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Tenant\Enums\OrganizationStatus;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<model-property<Organization>, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'tenant_id' => Tenant::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'business_type' => 'Retail',
            'country' => 'BD',
            'timezone' => 'Asia/Dhaka',
            'default_currency' => 'BDT',
            'contact_email' => $this->faker->unique()->companyEmail(),
            'contact_number' => '+8801700000000',
            'status' => OrganizationStatus::Active,
            'settings' => [],
            'activated_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => OrganizationStatus::Pending,
            'activated_at' => null,
        ]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $tenant->getKey(),
            'timezone' => $tenant->timezone,
            'default_currency' => $tenant->default_currency,
        ]);
    }
}
