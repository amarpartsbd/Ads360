<?php

declare(strict_types=1);

namespace App\Domains\Analytics\DTOs;

use App\Support\Values\Money;

/**
 * A period's performance, with every derived figure computed here rather than
 * in a browser (Rule 8, spec §38).
 *
 * The derived rates are the interesting part. Each is a ratio of two integers
 * this object already holds, and each is computed with integer arithmetic and
 * an explicit guard for a zero denominator — a campaign with no impressions
 * has no click-through rate, and showing "0%" would claim it performed badly
 * rather than that it has not run.
 */
final readonly class PerformanceTotals
{
    public function __construct(
        public Money $spend,
        public int $impressions,
        public int $clicks,
        public int $reach,
        public int $conversions,
        public Money $conversionValue,
    ) {}

    public static function empty(string $currency): self
    {
        return new self(
            spend: Money::zero($currency),
            impressions: 0,
            clicks: 0,
            reach: 0,
            conversions: 0,
            conversionValue: Money::zero($currency),
        );
    }

    /**
     * Click-through rate as a percentage string, or null when there is nothing
     * to divide by.
     */
    public function clickThroughRate(): ?string
    {
        if ($this->impressions === 0) {
            return null;
        }

        // Two decimal places, via integers, so the value does not depend on
        // float rounding.
        return number_format($this->clicks * 10000 / $this->impressions / 100, 2);
    }

    /** Average cost of a click. Null when nobody has clicked. */
    public function costPerClick(): ?Money
    {
        return $this->clicks === 0 ? null : $this->spend->dividedBy($this->clicks);
    }

    /** Cost per thousand impressions. Null when there have been none. */
    public function costPerMille(): ?Money
    {
        if ($this->impressions === 0) {
            return null;
        }

        return $this->spend->multipliedBy(1000)->dividedBy($this->impressions);
    }

    /** Average cost of a conversion. Null when there have been none. */
    public function costPerConversion(): ?Money
    {
        return $this->conversions === 0 ? null : $this->spend->dividedBy($this->conversions);
    }

    /**
     * Return on ad spend, as a ratio to two decimals. Null when nothing has
     * been spent — dividing by zero spend would report an infinite return.
     */
    public function returnOnAdSpend(): ?string
    {
        if ($this->spend->minorUnits === 0) {
            return null;
        }

        return number_format(
            $this->conversionValue->minorUnits * 100 / $this->spend->minorUnits / 100,
            2,
        );
    }

    /**
     * Ready for a prop: formatted strings, and nulls where a figure genuinely
     * does not exist rather than zeroes pretending it does.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'spend' => $this->spend->format(),
            'spendMinor' => $this->spend->minorUnits,
            // The currency travels with the figures, so anything comparing
            // two rows can tell whether they are comparable at all.
            'currency' => $this->spend->currency->code,
            'impressions' => $this->impressions,
            'clicks' => $this->clicks,
            'reach' => $this->reach,
            'conversions' => $this->conversions,
            'conversionValue' => $this->conversionValue->format(),
            'clickThroughRate' => $this->clickThroughRate(),
            'costPerClick' => $this->costPerClick()?->format(),
            'costPerMille' => $this->costPerMille()?->format(),
            'costPerConversion' => $this->costPerConversion()?->format(),
            // The minor-unit figure as well as the formatted one: a comparison
            // between two campaigns has to be arithmetic, not string sorting.
            'costPerConversionMinor' => $this->costPerConversion()?->minorUnits,
            'returnOnAdSpend' => $this->returnOnAdSpend(),
        ];
    }
}
