<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Identity\Enums\RoleScope;
use App\Domains\Identity\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Role '.Str::title($this->faker->unique()->word());

        return [
            'tenant_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'scope' => RoleScope::Organization,
            'description' => null,
            'is_system' => false,
        ];
    }

    public function platform(): static
    {
        return $this->state(fn (): array => ['scope' => RoleScope::Platform]);
    }

    public function system(): static
    {
        return $this->state(fn (): array => ['is_system' => true]);
    }
}
