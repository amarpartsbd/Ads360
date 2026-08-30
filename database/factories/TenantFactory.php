<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Tenant\Enums\TenantStatus;
use App\Domains\Tenant\Enums\TenantType;
use App\Domains\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'type' => TenantType::DirectClient,
            'status' => TenantStatus::Active,
            'billing_email' => $this->faker->unique()->companyEmail(),
            'country' => 'BD',
            'timezone' => 'Asia/Dhaka',
            'default_currency' => 'BDT',
            'branding' => [],
            'settings' => [],
        ];
    }

    public function agency(): static
    {
        return $this->state(fn (): array => ['type' => TenantType::Agency]);
    }

    public function reseller(): static
    {
        return $this->state(fn (): array => ['type' => TenantType::Reseller]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => TenantStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => 'Suspended in test fixture.',
        ]);
    }
}
