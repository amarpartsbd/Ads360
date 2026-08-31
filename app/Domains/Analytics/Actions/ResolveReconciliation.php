<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Actions;

use App\Domains\Analytics\Enums\ReconciliationStatus;
use App\Domains\Analytics\Models\SpendReconciliation;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Settles a spend discrepancy (spec §78, §25).
 *
 * Settling means recording a decision about it, not making the numbers match.
 * If money genuinely needs to move, that happens through the wallet adjustment
 * path with its own approval — this action closes the investigation and says
 * who closed it and why.
 *
 * The note is required. A discrepancy closed with no explanation is worse than
 * one left open: it looks settled to everyone who comes after, and the reason
 * is gone.
 */
final class ResolveReconciliation
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(SpendReconciliation $reconciliation, User $actor, string $note): SpendReconciliation
    {
        if ($reconciliation->status === ReconciliationStatus::Resolved) {
            return $reconciliation;
        }

        $note = trim($note);

        if ($note === '') {
            throw new RuntimeException('Say why this discrepancy is settled before closing it.');
        }

        $before = AuditRecorder::snapshot($reconciliation);

        DB::transaction(function () use ($reconciliation, $actor, $note): void {
            $reconciliation->forceFill([
                'status' => ReconciliationStatus::Resolved,
                'resolution_note' => $note,
                'resolved_by' => $actor->getKey(),
                'resolved_at' => Carbon::now(),
            ])->save();
        });

        $this->audit->recordChange(
            action: AuditAction::SpendDiscrepancyResolved,
            resource: $reconciliation,
            before: $before,
            context: $reconciliation->describe(),
            actor: $actor,
        );

        return $reconciliation;
    }
}
