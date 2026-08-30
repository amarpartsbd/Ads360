<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Actions;

use App\Domains\Advertising\Enums\PoolStatus;
use App\Domains\Advertising\Exceptions\AdAccountException;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Models\AdAccountPool;
use App\Domains\Advertising\Models\AdAccountPoolMember;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adds accounts to a pool, removes them, and adjusts their weights (spec §18).
 *
 * Membership is checked for shape before it is written: an account whose
 * provider or currency differs from the pool's would make the pool's own
 * comparisons meaningless, so it is refused rather than quietly skipped at
 * allocation time.
 */
final class ManagePoolMembership
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function add(AdAccountPool $pool, AdAccount $account, User $actor, int $weight = 1): AdAccountPoolMember
    {
        $this->assertEditable($pool);
        $this->assertCompatible($pool, $account);

        $weight = max(1, $weight);

        $member = DB::transaction(fn (): AdAccountPoolMember => AdAccountPoolMember::query()->updateOrCreate(
            [
                'ad_account_pool_id' => $pool->getKey(),
                'ad_account_id' => $account->getKey(),
            ],
            [
                'weight' => $weight,
                'added_by' => $actor->getKey(),
            ],
        ));

        $this->audit->record(
            action: AuditAction::AdAccountPoolMembershipChanged,
            resource: $pool,
            after: [
                'change' => 'added',
                'account' => $account->public_id,
                'weight' => $weight,
            ],
            actor: $actor,
        );

        return $member;
    }

    public function remove(AdAccountPool $pool, AdAccount $account, User $actor): void
    {
        $this->assertEditable($pool);

        $removed = DB::transaction(fn (): int => AdAccountPoolMember::query()
            ->where('ad_account_pool_id', $pool->getKey())
            ->where('ad_account_id', $account->getKey())
            ->delete());

        if ($removed === 0) {
            return;
        }

        $this->audit->record(
            action: AuditAction::AdAccountPoolMembershipChanged,
            resource: $pool,
            before: [
                'change' => 'removed',
                'account' => $account->public_id,
            ],
            actor: $actor,
        );
    }

    public function setWeight(AdAccountPool $pool, AdAccount $account, int $weight, User $actor): AdAccountPoolMember
    {
        return $this->add($pool, $account, $actor, $weight);
    }

    private function assertEditable(AdAccountPool $pool): void
    {
        if ($pool->status === PoolStatus::Archived) {
            throw AdAccountException::poolNotEditable();
        }
    }

    private function assertCompatible(AdAccountPool $pool, AdAccount $account): void
    {
        if ($account->provider !== $pool->provider) {
            throw AdAccountException::providerMismatch();
        }

        if (strtoupper($account->currency) !== strtoupper($pool->currency)) {
            throw AdAccountException::currencyMismatch();
        }
    }
}
