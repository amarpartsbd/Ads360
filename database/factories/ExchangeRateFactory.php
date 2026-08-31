<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Billing\Models\ExchangeRate;
use App\Domains\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    protected $model = ExchangeRate::class;

    /**
     * @return array<model-property<ExchangeRate>, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'base_currency' => 'USD',
            'quote_currency' => 'BDT',
            'market_rate' => '120.00000000',
            // A little above market: the platform's currency markup (spec §36).
            'client_rate' => '123.60000000',
            'effective_from' => now()->subDay(),
            'effective_until' => null,
            'source' => 'MANUAL',
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->getKey()]);
    }

    public function pair(string $base, string $quote): static
    {
        return $this->state(fn (): array => [
            'base_currency' => $base,
            'quote_currency' => $quote,
        ]);
    }
}
