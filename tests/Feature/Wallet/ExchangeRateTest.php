<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Domains\Billing\Exceptions\MissingExchangeRate;
use App\Domains\Billing\Models\ExchangeRate;
use App\Domains\Billing\Services\ExchangeRateService;
use App\Domains\Tenant\Models\Tenant;
use App\Support\Values\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The exchange rate engine (spec §35).
 *
 * The property that matters most: a historical transaction is always read back
 * against the rate that applied when it happened, never today's.
 */
final class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_converts_at_the_client_rate_not_the_market_rate(): void
    {
        ExchangeRate::factory()->create([
            'market_rate' => '120.00000000',
            'client_rate' => '123.60000000',
        ]);

        $result = app(ExchangeRateService::class)->convert(Money::of('100.00', 'USD'), 'BDT');

        // 100 USD × 123.60, not × 120.
        $this->assertSame('12360.00', $result['amount']->toDecimal());
        $this->assertSame('BDT', $result['amount']->currency->code);
    }

    #[Test]
    public function publishing_a_rate_closes_the_previous_one(): void
    {
        $service = app(ExchangeRateService::class);

        $first = $service->publish('USD', 'BDT', '120.00000000', '123.00000000');
        $second = $service->publish('USD', 'BDT', '122.00000000', '125.00000000');

        $first->refresh();

        $this->assertNotNull($first->effective_until, 'The old rate should have been closed off.');
        $this->assertNull($second->effective_until);
        $this->assertTrue($second->isCurrent());
    }

    #[Test]
    public function a_historical_conversion_uses_the_rate_that_applied_then(): void
    {
        $service = app(ExchangeRateService::class);

        $service->publish('USD', 'BDT', '100.00000000', '100.00000000',
            effectiveFrom: Carbon::now()->subDays(30));
        $service->publish('USD', 'BDT', '200.00000000', '200.00000000',
            effectiveFrom: Carbon::now()->subDays(1));

        $historical = $service->convert(
            Money::of('10.00', 'USD'),
            'BDT',
            at: Carbon::now()->subDays(10),
        );

        // Recalculating history with today's rate would double the figure.
        $this->assertSame('1000.00', $historical['amount']->toDecimal());

        $current = $service->convert(Money::of('10.00', 'USD'), 'BDT');
        $this->assertSame('2000.00', $current['amount']->toDecimal());
    }

    #[Test]
    public function a_tenant_rate_overrides_the_platform_rate(): void
    {
        $tenant = Tenant::factory()->create();
        $service = app(ExchangeRateService::class);

        $service->publish('USD', 'BDT', '120.00000000', '123.00000000');
        $service->publish('USD', 'BDT', '120.00000000', '121.00000000', tenant: $tenant);

        $platform = $service->convert(Money::of('100.00', 'USD'), 'BDT');
        $tenantRate = $service->convert(Money::of('100.00', 'USD'), 'BDT', tenant: $tenant);

        $this->assertSame('12300.00', $platform['amount']->toDecimal());
        $this->assertSame('12100.00', $tenantRate['amount']->toDecimal());
    }

    #[Test]
    public function a_tenant_without_its_own_rate_falls_back_to_the_platform(): void
    {
        $tenant = Tenant::factory()->create();
        app(ExchangeRateService::class)->publish('USD', 'BDT', '120.00000000', '123.00000000');

        $result = app(ExchangeRateService::class)
            ->convert(Money::of('100.00', 'USD'), 'BDT', tenant: $tenant);

        $this->assertSame('12300.00', $result['amount']->toDecimal());
    }

    #[Test]
    public function a_missing_rate_fails_rather_than_guessing(): void
    {
        // Converting at an assumed rate is worse than refusing to convert.
        $this->expectException(MissingExchangeRate::class);

        app(ExchangeRateService::class)->convert(Money::of('100.00', 'USD'), 'BDT');
    }

    #[Test]
    public function every_conversion_returns_the_rate_snapshot_to_store(): void
    {
        app(ExchangeRateService::class)->publish('USD', 'BDT', '120.00000000', '123.60000000');

        $result = app(ExchangeRateService::class)->convert(Money::of('100.00', 'USD'), 'BDT');

        $this->assertSame('123.60000000', $result['snapshot']['client_rate']);
        $this->assertSame('120.00000000', $result['snapshot']['market_rate']);
        $this->assertArrayHasKey('rate_id', $result['snapshot']);
    }

    #[Test]
    public function two_current_rates_for_one_pair_cannot_exist(): void
    {
        ExchangeRate::factory()->create(['effective_until' => null]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        // A second open-ended row would make "the current rate" depend on
        // query order.
        ExchangeRate::factory()->create(['effective_until' => null]);
    }

    #[Test]
    public function the_markup_is_reported_against_the_market_rate(): void
    {
        $rate = ExchangeRate::factory()->create([
            'market_rate' => '100.00000000',
            'client_rate' => '103.00000000',
        ]);

        $this->assertSame('3.00', $rate->markupPercentage());
    }
}
