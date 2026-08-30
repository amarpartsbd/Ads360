<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Enums;

/**
 * How a pool picks between the accounts that pass its rules (spec §18, §19).
 *
 * The rules decide which accounts are eligible; the strategy decides which of
 * the eligible ones is handed out. Keeping the two apart means an operator can
 * change the tie-break without reopening the eligibility question.
 */
enum SelectionStrategy: string
{
    case LeastLoaded = 'LEAST_LOADED';
    case HighestPriority = 'HIGHEST_PRIORITY';
    case Weighted = 'WEIGHTED';
    case RoundRobin = 'ROUND_ROBIN';
    case LowestRisk = 'LOWEST_RISK';

    public function label(): string
    {
        return match ($this) {
            self::LeastLoaded => 'Least loaded',
            self::HighestPriority => 'Highest priority first',
            self::Weighted => 'Weighted share',
            self::RoundRobin => 'Round robin',
            self::LowestRisk => 'Lowest risk first',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::LeastLoaded => 'Prefers the account with the most unused headroom against its limits.',
            self::HighestPriority => 'Prefers the account an administrator ranked highest.',
            self::Weighted => 'Distributes across accounts in proportion to the weight set on each membership.',
            self::RoundRobin => 'Takes accounts in turn, so load spreads evenly over time.',
            self::LowestRisk => 'Prefers the account carrying the lowest risk score.',
        };
    }

    /** Whether membership weight is meaningful under this strategy. */
    public function usesWeight(): bool
    {
        return $this === self::Weighted;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
