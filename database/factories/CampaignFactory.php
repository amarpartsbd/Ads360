<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Campaign\Enums\BudgetType;
use App\Domains\Campaign\Enums\CampaignObjective;
use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Wallet\Models\WalletReservation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            // Taken from the organization rather than ambient context: a
            // factory used outside a request has no context to read.
            'tenant_id' => fn (array $attributes): int => Organization::query()
                ->withoutGlobalScopes()
                ->whereKey($attributes['organization_id'])
                ->value('tenant_id'),
            'name' => $this->faker->catchPhrase().' Campaign',
            'provider' => Provider::Meta,
            'objective' => CampaignObjective::Traffic,
            'status' => CampaignStatus::Draft,
            'currency' => 'BDT',
            'budget_type' => BudgetType::Lifetime,
            // 10,000.00 BDT in minor units (spec §59).
            'budget_amount' => 1_000_000,
            'starts_at' => Carbon::now()->addDay(),
            'ends_at' => Carbon::now()->addDays(15),
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

    public function provider(Provider $provider): static
    {
        return $this->state(fn (): array => ['provider' => $provider]);
    }

    public function objective(CampaignObjective $objective): static
    {
        return $this->state(fn (): array => ['objective' => $objective]);
    }

    public function budget(int $minorUnits, BudgetType $type = BudgetType::Lifetime): static
    {
        return $this->state(fn (): array => [
            'budget_amount' => $minorUnits,
            'budget_type' => $type,
        ]);
    }

    /** Submitted and waiting for a reviewer, with the price already frozen. */
    public function pendingReview(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CampaignStatus::PendingReview,
            'submitted_at' => Carbon::now(),
            'fee_total' => 0,
            'charged_total' => $attributes['budget_amount'],
        ]);
    }

    /**
     * Approved, which in this system means *resourced*: money held and an
     * account allocated. Both are required rather than invented, because the
     * check constraint refuses an approved row without them — and a factory
     * that could fabricate one would let a test assert something the database
     * would never allow.
     *
     * Tests that are exercising approval itself should go through
     * ApproveCampaign rather than this state.
     */
    public function resourcedWith(
        AdAccount $account,
        WalletReservation $reservation,
        CampaignStatus $status = CampaignStatus::Approved,
    ): static {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
            'submitted_at' => Carbon::now()->subHour(),
            'reviewed_at' => Carbon::now(),
            'charged_total' => $reservation->amount,
            'fee_total' => max(0, $reservation->amount - $attributes['budget_amount']),
            'ad_account_id' => $account->getKey(),
            'wallet_reservation_id' => $reservation->getKey(),
        ]);
    }

    /** A campaign that already exists at the provider. */
    public function publishedWith(AdAccount $account, WalletReservation $reservation): static
    {
        return $this->resourcedWith($account, $reservation, CampaignStatus::Active)
            ->state(fn (): array => [
                'provider_campaign_id' => 'mock-campaign-'.$this->faker->unique()->numerify('##########'),
                'published_at' => Carbon::now(),
            ]);
    }

    public function status(CampaignStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
