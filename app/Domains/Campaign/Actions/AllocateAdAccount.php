<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Actions;

use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Models\AdAccountPool;
use App\Domains\Advertising\Services\AccountSelector;
use App\Domains\Advertising\Services\PoolEligibilityService;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Campaign\Exceptions\AllocationFailed;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gives a campaign an ad account to run on (spec §19).
 *
 * The whole difficulty is that eligibility is read before the choice is made
 * and can stop being true before the choice is written. Two campaigns approved
 * at the same moment can both read the same account as having room, and if
 * both then write, the account is committed past its limit — and the provider,
 * not the platform, discovers it.
 *
 * So the read is treated as a *shortlist*, never as a decision:
 *
 *   1. eligible accounts are gathered without locks, which is cheap and may be
 *      stale by the time it returns;
 *   2. the pool's strategy orders them, so the first candidate is the one the
 *      pool would prefer;
 *   3. each candidate in turn is locked with `SELECT … FOR UPDATE`, **re-read
 *      under that lock**, and re-checked. Only then is the commitment written.
 *
 * Step 3 is the one that matters. Re-reading after locking is what makes the
 * check and the write atomic; locking and then trusting the earlier read would
 * be the same race with extra ceremony.
 *
 * **Lock ordering.** The approval pipeline locks the wallet first (to reserve
 * the budget) and the ad account second. Everything in this system that takes
 * both must do so in that order, or two approvals will deadlock holding one
 * lock each.
 */
final class AllocateAdAccount
{
    public function __construct(
        private readonly PoolEligibilityService $eligibility,
        private readonly AccountSelector $selector,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Must be called inside a transaction — the row lock it takes is only
     * meaningful for the life of one.
     *
     * @throws AllocationFailed
     */
    public function handle(Campaign $campaign, ?User $actor = null): AdAccount
    {
        $organization = Organization::query()
            ->withoutGlobalScopes()
            ->whereKey($campaign->organization_id)
            ->firstOrFail();

        $pools = $this->candidatePools($campaign);

        if ($pools === []) {
            throw AllocationFailed::noPool();
        }

        // The advertising spend the account has to be able to carry. Fees are
        // the platform's, not the provider's, so they are not part of what the
        // ad account commits to.
        $required = $campaign->committedBudget()->minorUnits;
        $reasons = [];

        foreach ($pools as $pool) {
            $clientFailures = $this->eligibility->clientFailures($pool, $organization);

            if ($clientFailures !== []) {
                $reasons = [...$reasons, ...array_map(
                    static fn (string $reason): string => "{$pool->name}: {$reason}",
                    $clientFailures,
                )];

                continue;
            }

            $shortlist = $this->selector->order(
                $pool,
                $this->eligibility->eligibleAccounts($pool, $organization, $required),
            );

            if ($shortlist === []) {
                $reasons[] = "{$pool->name}: no account has room for this campaign.";

                continue;
            }

            $account = $this->claimFirstAvailable($pool, $shortlist, $required);

            if ($account !== null) {
                // Recorded on the campaign so the share it holds of the
                // account's headroom can later be given back exactly once.
                $campaign->account_commitment = $required;

                $this->recordAllocation($campaign, $pool, $account, $organization, $actor);

                return $account;
            }

            $reasons[] = "{$pool->name}: every candidate was taken by another campaign.";
        }

        throw AllocationFailed::noEligibleAccount($reasons);
    }

    /**
     * Try each candidate under a lock until one of them still qualifies.
     *
     * @param  list<AdAccount>  $shortlist
     */
    private function claimFirstAvailable(AdAccountPool $pool, array $shortlist, int $required): ?AdAccount
    {
        foreach ($shortlist as $candidate) {
            /** @var AdAccount|null $locked */
            $locked = AdAccount::query()
                ->whereKey($candidate->getKey())
                ->lockForUpdate()
                // Re-read, not reuse. The row in $candidate was read before the
                // lock existed; anything decided from it would be a decision
                // about the past.
                ->first();

            if ($locked === null) {
                continue;
            }

            if ($this->eligibility->accountFailures($pool, $locked, $required) !== []) {
                continue;
            }

            $locked->committed_amount += $required;
            $locked->last_allocated_at = CarbonImmutable::now();
            $locked->save();

            return $locked;
        }

        return null;
    }

    /**
     * Active pools matching the campaign's provider and currency, most
     * preferred first.
     *
     * Read without the tenant scope: allocation runs from the approval
     * pipeline where the acting user is platform staff, and the inventory is
     * not tenant-owned in any case.
     *
     * @return list<AdAccountPool>
     */
    private function candidatePools(Campaign $campaign): array
    {
        return AdAccountPool::query()
            ->allocatable()
            ->forProvider($campaign->provider)
            ->where('currency', $campaign->currency)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function recordAllocation(
        Campaign $campaign,
        AdAccountPool $pool,
        AdAccount $account,
        Organization $organization,
        ?User $actor,
    ): void {
        DB::afterCommit(function () use ($campaign, $pool, $account, $organization, $actor): void {
            // Audited after commit so a rolled-back approval leaves no record
            // of an allocation that never happened.
            $this->audit->record(
                action: AuditAction::AdAccountAllocated,
                resource: $campaign,
                after: [
                    'ad_account' => $account->public_id,
                    'pool' => $pool->slug,
                    'committed' => $campaign->committedBudget()->toDecimal(),
                ],
                context: ['strategy' => $pool->selection_strategy->value],
                organization: $organization,
                actor: $actor,
            );
        });
    }
}
