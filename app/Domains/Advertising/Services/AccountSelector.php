<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Services;

use App\Domains\Advertising\Enums\SelectionStrategy;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Models\AdAccountPool;

/**
 * Orders the accounts a pool considers eligible, so the first one that can
 * actually take the work is the one the pool would prefer (spec §19).
 *
 * Ordering rather than picking: the caller still has to lock its choice and
 * re-check it, and if that one has been taken in the meantime it moves to the
 * next. A selector that returned a single account would force the caller to
 * start over on every lost race.
 *
 * Every strategy produces a total order with a deterministic tie-break on the
 * account key. Two identical requests arriving together should try the same
 * accounts in the same sequence; the row lock decides which wins, not the
 * order in which the database happened to return rows.
 */
final class AccountSelector
{
    /**
     * @param  list<AdAccount>  $accounts
     * @return list<AdAccount>
     */
    public function order(AdAccountPool $pool, array $accounts): array
    {
        $ordered = match ($pool->selection_strategy) {
            SelectionStrategy::LeastLoaded => $this->byHeadroom($accounts),
            SelectionStrategy::HighestPriority => $this->byPriority($accounts),
            SelectionStrategy::LowestRisk => $this->byRisk($accounts),
            SelectionStrategy::RoundRobin => $this->byLeastRecentlyUsed($accounts),
            SelectionStrategy::Weighted => $this->byWeight($pool, $accounts),
        };

        return $ordered;
    }

    /**
     * Most unused headroom first. An account with no limit configured is not
     * "infinitely free" — it is unconstrained at this level, so it sorts after
     * accounts whose spare capacity is known, rather than monopolising every
     * allocation.
     *
     * @param  list<AdAccount>  $accounts
     * @return list<AdAccount>
     */
    private function byHeadroom(array $accounts): array
    {
        return $this->sort($accounts, static function (AdAccount $a, AdAccount $b): int {
            $left = $a->dailyHeadroom()?->minorUnits;
            $right = $b->dailyHeadroom()?->minorUnits;

            if ($left === $right) {
                return 0;
            }

            if ($left === null) {
                return 1;
            }

            if ($right === null) {
                return -1;
            }

            return $right <=> $left;
        });
    }

    /**
     * @param  list<AdAccount>  $accounts
     * @return list<AdAccount>
     */
    private function byPriority(array $accounts): array
    {
        return $this->sort(
            $accounts,
            static fn (AdAccount $a, AdAccount $b): int => $b->allocation_priority <=> $a->allocation_priority,
        );
    }

    /**
     * @param  list<AdAccount>  $accounts
     * @return list<AdAccount>
     */
    private function byRisk(array $accounts): array
    {
        return $this->sort(
            $accounts,
            static fn (AdAccount $a, AdAccount $b): int => $a->risk_score <=> $b->risk_score,
        );
    }

    /**
     * Least recently allocated first, with never-allocated accounts ahead of
     * everything — a new account should get work before an old one gets more.
     *
     * @param  list<AdAccount>  $accounts
     * @return list<AdAccount>
     */
    private function byLeastRecentlyUsed(array $accounts): array
    {
        return $this->sort($accounts, static function (AdAccount $a, AdAccount $b): int {
            $left = $a->last_allocated_at?->getTimestamp();
            $right = $b->last_allocated_at?->getTimestamp();

            if ($left === $right) {
                return 0;
            }

            if ($left === null) {
                return -1;
            }

            if ($right === null) {
                return 1;
            }

            return $left <=> $right;
        });
    }

    /**
     * Weighted share, expressed as an ordering.
     *
     * Each account's claim is its weight divided by the work it already holds,
     * so a heavier account is preferred until its share of the load matches
     * its share of the weight. Comparing the two as a cross-multiplication
     * keeps it in integers — a float ratio would make the order depend on
     * rounding.
     *
     * @param  list<AdAccount>  $accounts
     * @return list<AdAccount>
     */
    private function byWeight(AdAccountPool $pool, array $accounts): array
    {
        $weights = $pool->members()
            ->pluck('weight', 'ad_account_id')
            ->all();

        return $this->sort($accounts, static function (AdAccount $a, AdAccount $b) use ($weights): int {
            $weightA = max(1, (int) ($weights[$a->getKey()] ?? 1));
            $weightB = max(1, (int) ($weights[$b->getKey()] ?? 1));

            // Load is spend plus commitments: what the account is already on
            // the hook for, not only what it has spent.
            $loadA = $a->current_daily_spend + $a->committed_amount;
            $loadB = $b->current_daily_spend + $b->committed_amount;

            // loadA/weightA <=> loadB/weightB, without dividing.
            return ($loadA * $weightB) <=> ($loadB * $weightA);
        });
    }

    /**
     * Sorts with a stable tie-break on the primary key, so equal candidates
     * are always tried in the same sequence.
     *
     * @param  list<AdAccount>  $accounts
     * @param  callable(AdAccount, AdAccount): int  $comparator
     * @return list<AdAccount>
     */
    private function sort(array $accounts, callable $comparator): array
    {
        usort($accounts, static function (AdAccount $a, AdAccount $b) use ($comparator): int {
            $result = $comparator($a, $b);

            return $result !== 0 ? $result : ($a->getKey() <=> $b->getKey());
        });

        return $accounts;
    }
}
