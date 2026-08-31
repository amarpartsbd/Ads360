<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Advertising\Enums\AdAccountBillingStatus;
use App\Domains\Advertising\Enums\AdAccountHealth;
use App\Domains\Advertising\Enums\AdAccountStatus;
use App\Domains\Advertising\Enums\PoolStatus;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\SelectionStrategy;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Models\AdAccountPool;
use App\Domains\Advertising\Values\AllocationRules;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A small managed inventory for development (spec §17, §18).
 *
 * Development only, and obviously fictional: the external account identifiers
 * are placeholders, not real provider accounts. Seeded so the ad infrastructure
 * screens have something to show before any provider access is approved.
 */
class AdInfrastructureSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $pool = $this->pool();

        foreach ($this->accounts() as $definition) {
            $account = AdAccount::query()->updateOrCreate(
                [
                    'provider' => $definition['provider'],
                    'external_account_id' => $definition['external_account_id'],
                ],
                [
                    'public_id' => (string) Str::ulid(),
                    ...$definition,
                ],
            );

            if ($account->provider === $pool->provider && $account->currency === $pool->currency) {
                $pool->accounts()->syncWithoutDetaching([$account->getKey() => ['weight' => 1]]);
            }
        }
    }

    private function pool(): AdAccountPool
    {
        return AdAccountPool::query()->firstOrCreate(
            ['slug' => 'meta-bdt-standard'],
            [
                'public_id' => (string) Str::ulid(),
                'name' => 'Meta BDT Standard',
                'description' => 'Verified clients running in Bangladeshi taka on Meta.',
                'provider' => Provider::Meta,
                'currency' => 'BDT',
                'status' => PoolStatus::Active,
                'allocation_rules' => AllocationRules::default()->toArray(),
                'selection_strategy' => SelectionStrategy::LeastLoaded,
                'priority' => 60,
            ],
        );
    }

    /**
     * @return list<array<model-property<AdAccount>, mixed>>
     */
    private function accounts(): array
    {
        return [
            [
                'provider' => Provider::Meta,
                'external_account_id' => 'act_demo_000000001',
                'name' => 'Ads360 Managed BD 01',
                'currency' => 'BDT',
                'timezone' => 'Asia/Dhaka',
                'status' => AdAccountStatus::Active,
                'health_status' => AdAccountHealth::Healthy,
                'billing_status' => AdAccountBillingStatus::Current,
                'daily_spend_limit' => 5_000_000,
                'monthly_spend_limit' => 100_000_000,
                'allocation_priority' => 70,
                'risk_score' => 10,
            ],
            [
                'provider' => Provider::Meta,
                'external_account_id' => 'act_demo_000000002',
                'name' => 'Ads360 Managed BD 02',
                'currency' => 'BDT',
                'timezone' => 'Asia/Dhaka',
                'status' => AdAccountStatus::Active,
                'health_status' => AdAccountHealth::Degraded,
                'billing_status' => AdAccountBillingStatus::Current,
                'daily_spend_limit' => 3_000_000,
                'monthly_spend_limit' => 60_000_000,
                'current_daily_spend' => 2_600_000,
                'allocation_priority' => 40,
                'risk_score' => 25,
            ],
            [
                'provider' => Provider::Meta,
                'external_account_id' => 'act_demo_000000003',
                'name' => 'Ads360 Managed BD 03',
                'currency' => 'BDT',
                'timezone' => 'Asia/Dhaka',
                // Shows the interface what a blocked account looks like, and
                // gives the eligibility screen a reason to display.
                'status' => AdAccountStatus::Active,
                'health_status' => AdAccountHealth::AtRisk,
                'billing_status' => AdAccountBillingStatus::PaymentFailed,
                'daily_spend_limit' => 2_000_000,
                'allocation_priority' => 30,
                'risk_score' => 60,
            ],
            [
                'provider' => Provider::Google,
                'external_account_id' => '000-demo-0001',
                'name' => 'Ads360 Managed Google 01',
                'currency' => 'BDT',
                'timezone' => 'Asia/Dhaka',
                'status' => AdAccountStatus::PendingSetup,
                'health_status' => AdAccountHealth::Unknown,
                'billing_status' => AdAccountBillingStatus::Unknown,
                'allocation_priority' => 50,
            ],
        ];
    }
}
