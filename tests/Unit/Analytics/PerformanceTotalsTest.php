<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Domains\Analytics\DTOs\PerformanceTotals;
use App\Support\Values\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The derived performance figures (spec §38, Rule 8).
 *
 * Each is a ratio of two numbers, and each has a denominator that can be zero.
 * The interesting cases are all about what happens then: "0%" claims a
 * campaign performed badly, where the truth is that it has not run.
 */
final class PerformanceTotalsTest extends TestCase
{
    #[Test]
    public function the_derived_rates_are_computed_from_the_stored_figures(): void
    {
        $totals = $this->totals(spend: 100_000, impressions: 20_000, clicks: 400, conversions: 20);

        // 400 of 20,000 is 2%.
        $this->assertSame('2.00', $totals->clickThroughRate());
        // 1000.00 over 400 clicks.
        $this->assertSame('BDT 2.50', $totals->costPerClick()?->format());
        // 1000.00 per 20,000 impressions, times a thousand.
        $this->assertSame('BDT 50.00', $totals->costPerMille()?->format());
        $this->assertSame('BDT 50.00', $totals->costPerConversion()?->format());
    }

    #[Test]
    public function a_rate_with_nothing_to_divide_by_is_null_not_zero(): void
    {
        $totals = PerformanceTotals::empty('BDT');

        // "0%" would say the campaign performed badly rather than that it has
        // not run.
        $this->assertNull($totals->clickThroughRate());
        $this->assertNull($totals->costPerClick());
        $this->assertNull($totals->costPerMille());
        $this->assertNull($totals->costPerConversion());
        $this->assertNull($totals->returnOnAdSpend());
    }

    #[Test]
    public function impressions_without_clicks_still_report_a_click_through_rate(): void
    {
        $totals = $this->totals(spend: 100_000, impressions: 5_000, clicks: 0, conversions: 0);

        // Zero clicks out of five thousand impressions is a real 0%, and is a
        // different statement from "no impressions yet".
        $this->assertSame('0.00', $totals->clickThroughRate());
        $this->assertNull($totals->costPerClick());
    }

    #[Test]
    public function return_on_ad_spend_needs_spend_to_divide_by(): void
    {
        $spent = new PerformanceTotals(
            spend: Money::ofMinor(100_000, 'BDT'),
            impressions: 1000,
            clicks: 10,
            reach: 900,
            conversions: 5,
            conversionValue: Money::ofMinor(400_000, 'BDT'),
        );

        $this->assertSame('4.00', $spent->returnOnAdSpend());

        $unspent = new PerformanceTotals(
            spend: Money::zero('BDT'),
            impressions: 0,
            clicks: 0,
            reach: 0,
            conversions: 0,
            conversionValue: Money::ofMinor(400_000, 'BDT'),
        );

        // Dividing by nothing would report an infinite return.
        $this->assertNull($unspent->returnOnAdSpend());
    }

    #[Test]
    public function the_array_form_carries_formatted_strings_and_honest_nulls(): void
    {
        $array = PerformanceTotals::empty('BDT')->toArray();

        $this->assertSame('BDT 0.00', $array['spend']);
        $this->assertNull($array['clickThroughRate']);
        $this->assertNull($array['costPerClick']);
    }

    private function totals(int $spend, int $impressions, int $clicks, int $conversions): PerformanceTotals
    {
        return new PerformanceTotals(
            spend: Money::ofMinor($spend, 'BDT'),
            impressions: $impressions,
            clicks: $clicks,
            reach: (int) ($impressions * 0.8),
            conversions: $conversions,
            conversionValue: Money::ofMinor(0, 'BDT'),
        );
    }
}
