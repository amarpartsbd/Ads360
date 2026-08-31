<?php

declare(strict_types=1);

namespace App\Domains\Advertising\DTOs;

use DateTimeImmutable;

/**
 * One day of performance for one campaign, as a provider reports it
 * (spec §38, §78).
 *
 * Every figure except the date is nullable, because §87 forbids assuming a
 * provider exposes any particular one. A null is "not reported" and is stored
 * as such; treating it as zero would show a client a day of no activity where
 * the truth is that nobody asked.
 *
 * The date is the provider's own, in the ad account's timezone. Re-interpreting
 * it would move spend across day boundaries and make the platform's figures
 * disagree with the provider's own interface — which is the first thing a
 * client checks.
 */
final readonly class DailyInsightRow
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public DateTimeImmutable $date,
        public ?int $spendMinor = null,
        public ?string $currency = null,
        public ?int $impressions = null,
        public ?int $clicks = null,
        public ?int $reach = null,
        public ?int $conversions = null,
        public ?int $conversionValueMinor = null,
        public array $raw = [],
    ) {}

    public function reportsSpend(): bool
    {
        return $this->spendMinor !== null;
    }
}
