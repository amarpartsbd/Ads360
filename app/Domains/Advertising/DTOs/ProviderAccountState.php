<?php

declare(strict_types=1);

namespace App\Domains\Advertising\DTOs;

/**
 * What a provider reports about one of the platform's managed ad accounts
 * (spec §17, §20).
 *
 * Every field is optional because §87 forbids assuming a provider exposes any
 * particular figure. A null means "not reported", which is different from zero
 * and must not be rendered as one.
 */
final readonly class ProviderAccountState
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $externalAccountId,
        public ?string $status = null,
        public ?string $billingStatus = null,
        public ?int $spentTodayMinor = null,
        public ?int $spentThisMonthMinor = null,
        public ?int $dailySpendLimitMinor = null,
        public ?int $monthlySpendLimitMinor = null,
        public ?string $currency = null,
        public ?string $disapprovalReason = null,
        public array $raw = [],
    ) {}
}
