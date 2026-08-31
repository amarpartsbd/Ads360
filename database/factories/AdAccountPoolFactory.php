<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Advertising\Enums\PoolStatus;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\SelectionStrategy;
use App\Domains\Advertising\Models\AdAccountPool;
use App\Domains\Advertising\Values\AllocationRules;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AdAccountPool>
 */
class AdAccountPoolFactory extends Factory
{
    protected $model = AdAccountPool::class;

    /**
     * @return array<model-property<AdAccountPool>, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true).' Pool';

        return [
            'name' => ucwords((string) $name),
            'slug' => Str::slug((string) $name).'-'.Str::lower(Str::random(6)),
            'description' => 'Created by a test fixture.',
            'provider' => Provider::Meta,
            'currency' => 'BDT',
            'status' => PoolStatus::Active,
            'allocation_rules' => AllocationRules::default()->toArray(),
            'selection_strategy' => SelectionStrategy::LeastLoaded,
            'priority' => 50,
        ];
    }

    public function provider(Provider $provider): static
    {
        return $this->state(fn (): array => ['provider' => $provider]);
    }

    public function currency(string $currency): static
    {
        return $this->state(fn (): array => ['currency' => strtoupper($currency)]);
    }

    public function status(PoolStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function strategy(SelectionStrategy $strategy): static
    {
        return $this->state(fn (): array => ['selection_strategy' => $strategy]);
    }

    public function rules(AllocationRules $rules): static
    {
        return $this->state(fn (): array => ['allocation_rules' => $rules->toArray()]);
    }

    public function priority(int $priority): static
    {
        return $this->state(fn (): array => ['priority' => $priority]);
    }
}
