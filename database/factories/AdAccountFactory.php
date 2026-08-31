<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Advertising\Enums\AdAccountBillingStatus;
use App\Domains\Advertising\Enums\AdAccountHealth;
use App\Domains\Advertising\Enums\AdAccountStatus;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Models\AdAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AdAccount>
 */
class AdAccountFactory extends Factory
{
    protected $model = AdAccount::class;

    /**
     * @return array<model-property<AdAccount>, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => Provider::Meta,
            'external_account_id' => (string) $this->faker->unique()->numerify('act_############'),
            'name' => $this->faker->company().' Managed Account',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'status' => AdAccountStatus::Active,
            'health_status' => AdAccountHealth::Healthy,
            'billing_status' => AdAccountBillingStatus::Current,
            // 50,000.00 BDT a day, in minor units (spec §59).
            'daily_spend_limit' => 5_000_000,
            'monthly_spend_limit' => 100_000_000,
            'current_daily_spend' => 0,
            'current_monthly_spend' => 0,
            'committed_amount' => 0,
            'risk_score' => 10,
            'allocation_priority' => 50,
            'last_synced_at' => Carbon::now(),
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

    /** No limits configured — headroom is unconstrained at this level. */
    public function withoutLimits(): static
    {
        return $this->state(fn (): array => [
            'daily_spend_limit' => null,
            'monthly_spend_limit' => null,
        ]);
    }

    public function spent(int $dailyMinor, int $monthlyMinor = 0): static
    {
        return $this->state(fn (): array => [
            'current_daily_spend' => $dailyMinor,
            'current_monthly_spend' => max($monthlyMinor, $dailyMinor),
        ]);
    }

    public function committed(int $minor): static
    {
        return $this->state(fn (): array => ['committed_amount' => $minor]);
    }

    public function health(AdAccountHealth $health): static
    {
        return $this->state(fn (): array => ['health_status' => $health]);
    }

    public function billing(AdAccountBillingStatus $billing): static
    {
        return $this->state(fn (): array => ['billing_status' => $billing]);
    }

    public function status(AdAccountStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => AdAccountStatus::Suspended,
            'health_status' => AdAccountHealth::Critical,
            'disabled_at' => Carbon::now(),
            'disabled_reason' => 'Suspended in test fixture.',
        ]);
    }

    public function priority(int $priority): static
    {
        return $this->state(fn (): array => ['allocation_priority' => $priority]);
    }

    public function risk(int $score): static
    {
        return $this->state(fn (): array => ['risk_score' => $score]);
    }
}
