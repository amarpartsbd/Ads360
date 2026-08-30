<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Actions;

use App\Domains\Advertising\Exceptions\AdAccountException;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Changes an account's settings — name, limits, priority, risk (spec §17).
 *
 * Spend counters are not settable here. They mirror what the provider reports
 * and are written only by sync; letting an operator type a spend figure in
 * would put a number in the inventory that no provider agrees with.
 */
final class UpdateAdAccount
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Nulls are meaningful for the limits — they clear the limit — so absence
     * is expressed by leaving the key out rather than by passing null.
     *
     * @param  array<string, mixed>  $changes
     */
    public function handle(AdAccount $account, array $changes, User $actor): AdAccount
    {
        $before = AuditRecorder::snapshot($account);

        if (array_key_exists('daily_spend_limit', $changes)) {
            $this->assertLimitCoversCommitment(
                $changes['daily_spend_limit'],
                $account->current_daily_spend + $account->committed_amount,
            );
        }

        if (array_key_exists('monthly_spend_limit', $changes)) {
            $this->assertLimitCoversCommitment(
                $changes['monthly_spend_limit'],
                $account->current_monthly_spend,
            );
        }

        $permitted = array_intersect_key($changes, array_flip([
            'name',
            'timezone',
            'daily_spend_limit',
            'monthly_spend_limit',
            'risk_score',
            'allocation_priority',
            'metadata',
        ]));

        DB::transaction(function () use ($account, $permitted): void {
            $account->fill($permitted)->save();
        });

        $this->audit->recordChange(
            action: AuditAction::AdAccountUpdated,
            resource: $account,
            before: $before,
            actor: $actor,
        );

        return $account;
    }

    /**
     * A limit below what is already spent and committed would leave the
     * account instantly over its own ceiling, which allocation would then read
     * as zero headroom on an account that is mid-flight.
     */
    private function assertLimitCoversCommitment(mixed $limit, int $alreadyUsed): void
    {
        if ($limit === null) {
            return;
        }

        if (! is_int($limit) || $limit < 0 || $limit < $alreadyUsed) {
            throw AdAccountException::limitBelowCommitment();
        }
    }
}
