<?php

declare(strict_types=1);

namespace App\Domains\Agency\DTOs;

use App\Support\Values\Money;
use DateTimeImmutable;
use Illuminate\Support\Collection;

/**
 * An agency's clients over a window, with the totals underneath (spec §42).
 *
 * ## Why the totals are conditional on one currency
 *
 * An agency may hold clients billed in different currencies. Adding taka to
 * dollars produces a number that is not money, and converting them here would
 * apply a rate this report chose, on a date this report chose, with no record
 * of either — which is precisely what the exchange-rate engine and the ledger
 * exist to prevent (Rule 8, §35, §59).
 *
 * So `totalSpend` is null when the roster spans currencies, and the screen
 * shows per-client figures without a meaningless sum. The counts still add up,
 * because a campaign is a campaign in any currency.
 */
final readonly class AgencyReport
{
    /**
     * @param  Collection<int, AgencyClientSummary>  $clients
     */
    public function __construct(
        public Collection $clients,
        public DateTimeImmutable $since,
        public DateTimeImmutable $until,
        public ?Money $totalSpend,
        public ?Money $totalBalance,
        public int $totalImpressions,
        public int $totalClicks,
        public int $totalConversions,
        public int $activeCampaigns,
        public int $clientCount,
        /** Currencies present on the roster, so a screen can say why a total is missing. */
        public array $currencies = [],
    ) {}

    public function spansCurrencies(): bool
    {
        return count($this->currencies) > 1;
    }

    /**
     * Clients that cannot spend right now, and would not appear in any spend
     * figure however long the window: unverified, suspended, or out of funds.
     *
     * @return Collection<int, AgencyClientSummary>
     */
    public function blocked(): Collection
    {
        return $this->clients->reject(
            static fn (AgencyClientSummary $client): bool => $client->canSpend(),
        )->values();
    }
}
