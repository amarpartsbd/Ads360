<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Domains\Billing\Enums\FeeType;
use App\Domains\Billing\Enums\PricingCalculation;
use App\Domains\Billing\Models\PricingPlan;
use App\Domains\Billing\Services\PricingEngine;
use App\Domains\Tenant\Models\Organization;
use App\Support\Values\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The pricing engine (spec §36, §67).
 */
final class PricingEngineTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_applies_a_percentage_fee(): void
    {
        PricingPlan::factory()->platformDefault()->withPlatformFee('7.5000')->create();
        $organization = Organization::factory()->create(['default_currency' => 'BDT']);

        $priced = app(PricingEngine::class)->price($organization, Money::of('100000.00', 'BDT'));

        $this->assertSame('7500.00', $priced->feeTotal->toDecimal());
        $this->assertSame('107500.00', $priced->total->toDecimal());
    }

    #[Test]
    public function tax_is_charged_on_the_fees_and_not_on_the_ad_budget(): void
    {
        // 15% of the 7,500 fee is 1,125 — not 15% of the 100,000 budget.
        PricingPlan::factory()
            ->platformDefault()
            ->withPlatformFee('7.5000')
            ->withTax('15.0000')
            ->create();

        $organization = Organization::factory()->create(['default_currency' => 'BDT']);

        $priced = app(PricingEngine::class)->price($organization, Money::of('100000.00', 'BDT'));

        $tax = collect($priced->fees)->firstWhere('type', FeeType::Tax);

        $this->assertNotNull($tax);
        $this->assertSame('1125.00', $tax->amount->toDecimal());
        $this->assertSame('108625.00', $priced->total->toDecimal());
    }

    #[Test]
    public function a_client_override_beats_the_tenant_plan_and_the_platform_default(): void
    {
        PricingPlan::factory()->platformDefault()->withPlatformFee('10.0000')->create();

        $organization = Organization::factory()->create(['default_currency' => 'BDT']);
        $tenant = $organization->tenant;

        PricingPlan::factory()->forTenant($tenant)->withPlatformFee('8.0000')->create();

        $priced = app(PricingEngine::class)->price($organization, Money::of('1000.00', 'BDT'));
        $this->assertSame('80.00', $priced->feeTotal->toDecimal(), 'The tenant plan should apply.');

        PricingPlan::factory()->forOrganization($organization)->withPlatformFee('5.0000')->create();

        $priced = app(PricingEngine::class)->price($organization->fresh(), Money::of('1000.00', 'BDT'));
        $this->assertSame('50.00', $priced->feeTotal->toDecimal(), 'The client override should win.');
    }

    #[Test]
    public function a_fixed_fee_is_charged_as_written(): void
    {
        $plan = PricingPlan::factory()->platformDefault()->create();
        $plan->rules()->create([
            'fee_type' => FeeType::CampaignSetupFee,
            'calculation' => PricingCalculation::Fixed,
            'fixed_amount' => 50000,
            'priority' => 10,
        ]);

        $organization = Organization::factory()->create(['default_currency' => 'BDT']);

        $priced = app(PricingEngine::class)->price($organization, Money::of('1000.00', 'BDT'));

        $this->assertSame('500.00', $priced->feeTotal->toDecimal());
    }

    #[Test]
    public function bounds_clamp_a_percentage_fee(): void
    {
        $plan = PricingPlan::factory()->platformDefault()->create();
        $plan->rules()->create([
            'fee_type' => FeeType::PlatformFee,
            'calculation' => PricingCalculation::Percentage,
            'percentage' => '10.0000',
            'minimum_amount' => 10000,   // floor of 100.00
            'maximum_amount' => 50000,   // ceiling of 500.00
            'priority' => 10,
        ]);

        $organization = Organization::factory()->create(['default_currency' => 'BDT']);
        $engine = app(PricingEngine::class);

        // 10% of 100 is 10, raised to the 100 floor.
        $this->assertSame(
            '100.00',
            $engine->price($organization, Money::of('100.00', 'BDT'))->feeTotal->toDecimal(),
        );

        // 10% of 100,000 is 10,000, capped at 500.
        $this->assertSame(
            '500.00',
            $engine->price($organization, Money::of('100000.00', 'BDT'))->feeTotal->toDecimal(),
        );
    }

    #[Test]
    public function a_rule_does_not_apply_below_its_threshold(): void
    {
        $plan = PricingPlan::factory()->platformDefault()->create();
        $plan->rules()->create([
            'fee_type' => FeeType::ManagementFee,
            'calculation' => PricingCalculation::Percentage,
            'percentage' => '5.0000',
            'applies_from_amount' => 100000, // only from 1,000.00 upwards
            'priority' => 10,
        ]);

        $organization = Organization::factory()->create(['default_currency' => 'BDT']);
        $engine = app(PricingEngine::class);

        $this->assertTrue(
            $engine->price($organization, Money::of('500.00', 'BDT'))->feeTotal->isZero(),
        );
        $this->assertSame(
            '100.00',
            $engine->price($organization, Money::of('2000.00', 'BDT'))->feeTotal->toDecimal(),
        );
    }

    #[Test]
    public function every_price_carries_the_plan_snapshot_that_produced_it(): void
    {
        PricingPlan::factory()->platformDefault()->withPlatformFee('7.5000')->create();
        $organization = Organization::factory()->create(['default_currency' => 'BDT']);

        $priced = app(PricingEngine::class)->price($organization, Money::of('1000.00', 'BDT'));

        // An invoice from six months ago must explain itself without depending
        // on the plan still looking the same (spec §36).
        $this->assertArrayHasKey('plan_id', $priced->pricingSnapshot);
        $this->assertArrayHasKey('rules', $priced->pricingSnapshot);
        $this->assertSame('7.5000', $priced->pricingSnapshot['rules'][0]['percentage']);
    }

    #[Test]
    public function pricing_fails_loudly_when_no_plan_is_configured(): void
    {
        $organization = Organization::factory()->create(['default_currency' => 'BDT']);

        $this->expectException(RuntimeException::class);

        app(PricingEngine::class)->price($organization, Money::of('1000.00', 'BDT'));
    }

    #[Test]
    public function pricing_refuses_an_amount_in_the_wrong_currency(): void
    {
        PricingPlan::factory()->platformDefault()->withPlatformFee('7.5000')->create();
        $organization = Organization::factory()->create(['default_currency' => 'BDT']);

        $this->expectException(RuntimeException::class);

        app(PricingEngine::class)->price($organization, Money::of('1000.00', 'USD'));
    }
}
