<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Billing\Enums\FeeType;
use App\Domains\Billing\Enums\PricingCalculation;
use App\Domains\Billing\Enums\PricingScope;
use App\Domains\Billing\Models\PricingPlan;
use App\Domains\Billing\Services\ExchangeRateService;
use Illuminate\Database\Seeder;

/**
 * The pricing and rate configuration the platform cannot operate without.
 *
 * Safe to run in every environment: without a platform default plan the
 * pricing engine has nothing to fall back to and refuses to price anything, so
 * this is part of the application's own definition rather than demo data.
 */
class FinanceSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedDefaultPricing();
        $this->seedAgencyPricing();
        $this->seedExchangeRates();
    }

    private function seedDefaultPricing(): void
    {
        if (PricingPlan::query()->where('is_default', true)->where('is_active', true)->exists()) {
            return;
        }

        /** @var PricingPlan $plan */
        $plan = PricingPlan::query()->create([
            'name' => 'Platform standard',
            'description' => 'The default fee schedule applied to any client without their own plan.',
            'scope' => PricingScope::Platform,
            'currency' => config('platform.default_currency'),
            'is_active' => true,
            'is_default' => true,
        ]);

        $plan->rules()->createMany([
            [
                'fee_type' => FeeType::PlatformFee,
                'calculation' => PricingCalculation::Percentage,
                'percentage' => '7.5000',
                'priority' => 10,
                'is_active' => true,
            ],
            [
                // Only on larger accounts, which is why it carries a threshold
                // rather than being a separate plan.
                'fee_type' => FeeType::ManagementFee,
                'calculation' => PricingCalculation::Percentage,
                'percentage' => '2.5000',
                'applies_from_amount' => 100_000_00,
                'priority' => 20,
                'is_active' => true,
            ],
            [
                // Computed last, over the fees rather than the ad budget.
                'fee_type' => FeeType::Tax,
                'calculation' => PricingCalculation::Percentage,
                'percentage' => '15.0000',
                'priority' => 90,
                'is_active' => true,
            ],
        ]);
    }

    /**
     * Fee schedules the platform offers an agency (spec §36, §42).
     *
     * Platform-scoped and not default, so they price nobody on their own: they
     * are the schedules an administrator assigns to a particular agency, which
     * copies them to a tenant-scoped plan for that agency alone.
     *
     * An agency pays less than a direct client because it brings its own
     * clients and does its own account management. That is a commercial
     * decision the platform makes, which is why an agency can read its plan
     * and never write one.
     */
    private function seedAgencyPricing(): void
    {
        $schedules = [
            [
                'name' => 'Agency standard',
                'description' => 'The fee schedule for an agency managing its own clients.',
                'platform' => '5.5000',
                'management' => '1.5000',
            ],
            [
                'name' => 'Reseller preferred',
                'description' => 'For resellers carrying their own client relationships and support.',
                'platform' => '4.0000',
                'management' => null,
            ],
        ];

        foreach ($schedules as $schedule) {
            if (PricingPlan::query()->where('name', $schedule['name'])->exists()) {
                continue;
            }

            /** @var PricingPlan $plan */
            $plan = PricingPlan::query()->create([
                'name' => $schedule['name'],
                'description' => $schedule['description'],
                'scope' => PricingScope::Platform,
                'currency' => config('platform.default_currency'),
                'is_active' => true,
                // Never the default: assigning it is a deliberate act.
                'is_default' => false,
            ]);

            $rules = [[
                'fee_type' => FeeType::PlatformFee,
                'calculation' => PricingCalculation::Percentage,
                'percentage' => $schedule['platform'],
                'priority' => 10,
                'is_active' => true,
            ]];

            if ($schedule['management'] !== null) {
                $rules[] = [
                    'fee_type' => FeeType::ManagementFee,
                    'calculation' => PricingCalculation::Percentage,
                    'percentage' => $schedule['management'],
                    'applies_from_amount' => 100_000_00,
                    'priority' => 20,
                    'is_active' => true,
                ];
            }

            $rules[] = [
                'fee_type' => FeeType::Tax,
                'calculation' => PricingCalculation::Percentage,
                'percentage' => '15.0000',
                'priority' => 90,
                'is_active' => true,
            ];

            $plan->rules()->createMany($rules);
        }
    }

    /**
     * A starting USD to BDT rate. Providers bill in USD while clients hold BDT,
     * so without this no campaign spend could be converted (spec §35).
     */
    private function seedExchangeRates(): void
    {
        $service = app(ExchangeRateService::class);

        if ($service->hasRate('USD', 'BDT')) {
            return;
        }

        $service->publish(
            base: 'USD',
            quote: 'BDT',
            marketRate: '120.00000000',
            // Three percent above market: the platform's currency markup.
            clientRate: '123.60000000',
            source: 'SEED',
            note: 'Initial rate. Replace with a live figure before taking real payments.',
        );
    }
}
