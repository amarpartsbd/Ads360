<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Enums;

/**
 * How the provider is told to bid (spec §21).
 */
enum BidStrategy: string
{
    case LowestCost = 'LOWEST_COST';
    case CostCap = 'COST_CAP';
    case BidCap = 'BID_CAP';
    case TargetCost = 'TARGET_COST';

    public function label(): string
    {
        return match ($this) {
            self::LowestCost => 'Lowest cost',
            self::CostCap => 'Cost cap',
            self::BidCap => 'Bid cap',
            self::TargetCost => 'Target cost',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::LowestCost => 'Get the most results for the budget, without a cap.',
            self::CostCap => 'Keep the average cost per result at or below your figure.',
            self::BidCap => 'Never bid more than your figure in any auction.',
            self::TargetCost => 'Keep the average cost per result close to your figure.',
        };
    }

    /** Whether an amount has to accompany the strategy. */
    public function requiresAmount(): bool
    {
        return $this !== self::LowestCost;
    }
}
