<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Actions;

use App\Domains\Advertising\Enums\AdAccountStatus;
use App\Domains\Advertising\Exceptions\AdAccountException;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Moves an account through its lifecycle (spec §17).
 *
 * The transition table on the enum is the authority. Going through this action
 * rather than assigning the column directly means an account cannot be
 * un-retired, and every move leaves an audit entry with its reason.
 */
final class ChangeAdAccountStatus
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(AdAccount $account, AdAccountStatus $status, User $actor, ?string $reason = null): AdAccount
    {
        $current = $account->status;

        if ($current === $status) {
            return $account;
        }

        if (! $current->canTransitionTo($status)) {
            throw AdAccountException::invalidTransition($current, $status);
        }

        $before = AuditRecorder::snapshot($account);

        DB::transaction(function () use ($account, $status, $reason): void {
            $account->status = $status;

            if (in_array($status, [AdAccountStatus::Suspended, AdAccountStatus::Retired], true)) {
                $account->disabled_at = CarbonImmutable::now();
                $account->disabled_reason = $reason;
            } elseif ($status === AdAccountStatus::Active) {
                // Coming back into service clears the record of why it left,
                // which is kept in the audit log rather than on the row.
                $account->disabled_at = null;
                $account->disabled_reason = null;
            }

            $account->save();
        });

        $this->audit->recordChange(
            action: AuditAction::AdAccountStatusChanged,
            resource: $account,
            before: $before,
            context: [
                'from' => $current->value,
                'to' => $status->value,
                'reason' => $reason,
            ],
            actor: $actor,
        );

        return $account;
    }
}
