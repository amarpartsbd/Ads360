<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Enums;

/**
 * How a campaign's budget is spent down (spec §21, §22).
 *
 * The distinction matters to the wallet, not just to the provider: a lifetime
 * budget is reserved once for its whole amount, while a daily budget has to be
 * reserved for the full run — days times daily — because the client's balance
 * has to cover the whole campaign before it is allowed to start.
 */
enum BudgetType: string
{
    case Daily = 'DAILY';
    case Lifetime = 'LIFETIME';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily budget',
            self::Lifetime => 'Total budget',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Daily => 'Spend up to this much each day the campaign runs.',
            self::Lifetime => 'Spend up to this much across the whole campaign.',
        };
    }

    /** A daily budget needs an end date, or the commitment is unbounded. */
    public function requiresEndDate(): bool
    {
        return $this === self::Daily;
    }
}
