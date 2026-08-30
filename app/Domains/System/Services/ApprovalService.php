<?php

declare(strict_types=1);

namespace App\Domains\System\Services;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use App\Domains\System\Enums\ApprovableAction;
use App\Domains\System\Enums\ApprovalStatus;
use App\Domains\System\Models\ApprovalDecision;
use App\Domains\System\Models\ApprovalRequest;
use App\Domains\Tenant\Models\Organization;
use App\Support\Values\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maker-checker (spec §25).
 *
 * Two rules do the work. The person who requested an action can never approve
 * it, and nobody may vote twice — the first is checked here and the second is
 * guaranteed by a unique index, so neither depends on the interface behaving.
 *
 * Executing an approved request is the caller's job: this service decides
 * *whether* something may proceed, not what it does.
 */
final class ApprovalService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Whether an action of this size needs approval before it takes effect.
     */
    public function isRequired(ApprovableAction $action, ?Money $amount): bool
    {
        return $action->requiresApproval($amount?->minorUnits);
    }

    /**
     * Record a request and put it in the queue.
     *
     * @param  array<string, mixed>  $payload  everything the executor will need
     */
    public function request(
        ApprovableAction $action,
        User $requester,
        string $summary,
        array $payload,
        ?Money $amount = null,
        ?Organization $organization = null,
        ?string $reason = null,
    ): ApprovalRequest {
        $request = ApprovalRequest::query()->create([
            'tenant_id' => $organization?->tenant_id,
            'organization_id' => $organization?->getKey(),
            'action_type' => $action,
            'summary' => $summary,
            'payload' => $payload,
            'amount' => $amount?->minorUnits,
            'currency' => $amount?->currency->code,
            'required_approvals' => $action->requiredApprovals($amount?->minorUnits),
            'status' => ApprovalStatus::Pending,
            'requested_by' => $requester->getKey(),
            'request_reason' => $reason,
        ]);

        $this->audit->record(
            action: AuditAction::ApprovalRequested,
            resource: $request,
            after: [
                'action' => $action->value,
                'amount' => $amount?->toDecimal(),
                'required_approvals' => $request->required_approvals,
            ],
            organization: $organization,
            actor: $requester,
        );

        return $request;
    }

    /**
     * Cast an approving vote.
     *
     * Returns the request. When the last needed approval lands the status moves
     * to APPROVED, which is the caller's signal to execute it.
     *
     * @throws ValidationException
     */
    public function approve(ApprovalRequest $request, User $approver, ?string $note = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $approver, $note): ApprovalRequest {
            $locked = $this->lockOpen($request);

            $this->assertMayDecide($locked, $approver);

            ApprovalDecision::query()->create([
                'approval_request_id' => $locked->getKey(),
                'approver_id' => $approver->getKey(),
                'decision' => ApprovalDecision::APPROVE,
                'note' => $note,
            ]);

            $received = $locked->approvals_received + 1;
            $satisfied = $received >= $locked->required_approvals;

            $locked->forceFill([
                'approvals_received' => $received,
                'status' => $satisfied ? ApprovalStatus::Approved : ApprovalStatus::Pending,
                'resolved_at' => $satisfied ? Carbon::now() : null,
            ])->save();

            $this->audit->record(
                action: AuditAction::ApprovalGranted,
                resource: $locked,
                after: ['approvals_received' => $received, 'status' => $locked->status->value],
                context: ['note' => $note],
                organization: $locked->organization()->first(),
                actor: $approver,
            );

            $request->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Refuse the request outright. One rejection ends it — requiring every
     * approver to reject would let a single approval stall a refusal.
     */
    public function reject(ApprovalRequest $request, User $approver, string $reason): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $approver, $reason): ApprovalRequest {
            $locked = $this->lockOpen($request);

            $this->assertMayDecide($locked, $approver);

            ApprovalDecision::query()->create([
                'approval_request_id' => $locked->getKey(),
                'approver_id' => $approver->getKey(),
                'decision' => ApprovalDecision::REJECT,
                'note' => $reason,
            ]);

            $locked->forceFill([
                'status' => ApprovalStatus::Rejected,
                'resolved_at' => Carbon::now(),
                'resolution_note' => $reason,
            ])->save();

            $this->audit->record(
                action: AuditAction::ApprovalRejected,
                resource: $locked,
                after: ['status' => ApprovalStatus::Rejected->value],
                context: ['reason' => $reason],
                organization: $locked->organization()->first(),
                actor: $approver,
            );

            $request->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Mark an approved request as carried out, recording what it produced.
     *
     * Locked and re-checked so a request cannot be executed twice by two
     * requests arriving together.
     */
    public function markExecuted(ApprovalRequest $request, ?object $result = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $result): ApprovalRequest {
            /** @var ApprovalRequest $locked */
            $locked = ApprovalRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== ApprovalStatus::Approved) {
                throw ValidationException::withMessages([
                    'approval' => 'Only an approved request can be executed.',
                ]);
            }

            $locked->forceFill([
                'status' => ApprovalStatus::Executed,
                'executed_at' => Carbon::now(),
                'result_type' => $result !== null ? $result::class : null,
                'result_id' => $result instanceof \Illuminate\Database\Eloquent\Model
                    ? (string) $result->getKey()
                    : null,
            ])->save();

            $request->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    private function lockOpen(ApprovalRequest $request): ApprovalRequest
    {
        /** @var ApprovalRequest $locked */
        $locked = ApprovalRequest::query()
            ->whereKey($request->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (! $locked->isOpen()) {
            throw ValidationException::withMessages([
                'approval' => 'That request has already been resolved.',
            ]);
        }

        return $locked;
    }

    private function assertMayDecide(ApprovalRequest $request, User $approver): void
    {
        if ($approver->getKey() === $request->requested_by) {
            throw ValidationException::withMessages([
                'approval' => 'You cannot approve a request you made yourself.',
            ]);
        }

        if (! $approver->hasPermissionTo($request->action_type->approvalPermission())) {
            throw ValidationException::withMessages([
                'approval' => 'You do not hold the permission needed to decide this request.',
            ]);
        }

        if ($request->decisions()->where('approver_id', $approver->getKey())->exists()) {
            throw ValidationException::withMessages([
                'approval' => 'You have already voted on this request.',
            ]);
        }
    }
}
