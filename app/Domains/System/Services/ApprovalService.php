<?php

declare(strict_types=1);

namespace App\Domains\System\Services;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Client\Models\OrganizationRiskProfile;
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
 *
 * Two things raise the bar beyond an action's own threshold. A movement large
 * enough to need two approvals also needs one of them to be *senior* — §25 asks
 * for "Finance + Senior Approval", which is two kinds of person rather than two
 * people. And a financial action on a high-risk client needs a second approver
 * whatever its size (§12), which is the only automatic consequence a risk score
 * has anywhere in this platform.
 */
final class ApprovalService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Whether an action of this size needs approval before it takes effect.
     *
     * Size is the usual answer. The other one is the client: a financial
     * action on a high-risk organization needs a second pair of eyes whatever
     * its size (spec §12). That is the *only* automatic consequence a risk
     * score has anywhere in this platform, and it deliberately adds a person
     * rather than taking one away — a scoring mistake should cost someone a
     * minute, not cost a client their advertising.
     */
    public function isRequired(
        ApprovableAction $action,
        ?Money $amount,
        ?Organization $organization = null,
    ): bool {
        if ($action->requiresApproval($amount?->minorUnits)) {
            return true;
        }

        return $this->riskElevation($action, $organization) !== null;
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
        $elevation = $this->riskElevation($action, $organization);

        /*
         * Whichever asks for more wins. A large movement on a high-risk client
         * does not need three signatures: two people have already looked, and
         * adding a third would slow every one of them down without anyone
         * seeing anything new.
         */
        $required = max($action->requiredApprovals($amount?->minorUnits), $elevation === null ? 1 : 2);

        $request = ApprovalRequest::query()->create([
            'tenant_id' => $organization?->tenant_id,
            'organization_id' => $organization?->getKey(),
            'action_type' => $action,
            'summary' => $summary,
            'payload' => $payload,
            'amount' => $amount?->minorUnits,
            'currency' => $amount?->currency->code,
            'required_approvals' => $required,
            'requires_senior_approval' => $action->requiresSeniorApproval($amount?->minorUnits),
            'elevation_reason' => $elevation,
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
                'requires_senior_approval' => $request->requires_senior_approval,
                'elevation_reason' => $elevation,
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

            /*
             * A senior signature is recorded the first time someone holding
             * that permission approves. It is a property of the request rather
             * than of the vote, because what §25 asks for is that a senior
             * person looked — not that they looked last.
             */
            $isSenior = $locked->requires_senior_approval
                && $locked->senior_approved_at === null
                && $approver->hasPermissionTo($locked->action_type->seniorApprovalPermission());

            $seniorSatisfied = ! $locked->requires_senior_approval
                || $locked->senior_approved_at !== null
                || $isSenior;

            /*
             * Both conditions, not either. A request that has its two
             * approvals but no senior signature stays pending, and the queue
             * says so — "Finance + Senior Approval" is two different kinds of
             * person, and treating a second finance signature as satisfying it
             * would make the requirement decorative.
             */
            $satisfied = $received >= $locked->required_approvals && $seniorSatisfied;

            $locked->forceFill([
                'approvals_received' => $received,
                'status' => $satisfied ? ApprovalStatus::Approved : ApprovalStatus::Pending,
                'resolved_at' => $satisfied ? Carbon::now() : null,
            ]);

            if ($isSenior) {
                $locked->forceFill([
                    'senior_approved_at' => Carbon::now(),
                    'senior_approved_by' => $approver->getKey(),
                ]);
            }

            $locked->save();

            $this->audit->record(
                action: AuditAction::ApprovalGranted,
                resource: $locked,
                after: [
                    'approvals_received' => $received,
                    'status' => $locked->status->value,
                    'senior' => $isSenior,
                ],
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

    /**
     * Why this request needs more scrutiny than its size alone asks for, or
     * null when it does not (spec §12).
     *
     * A sentence rather than a flag, because it is shown to the approver: "a
     * second approver is required" with no reason is an instruction, and an
     * instruction nobody can evaluate is one people learn to click through.
     *
     * Reads the stored profile rather than reassessing. An assessment here
     * would put a fan of queries across payments, campaigns and the ledger in
     * front of every financial action, and would let a slow read block a
     * refund.
     */
    private function riskElevation(ApprovableAction $action, ?Organization $organization): ?string
    {
        if ($organization === null || ! $action->isFinancial()) {
            return null;
        }

        /** @var OrganizationRiskProfile|null $profile */
        $profile = OrganizationRiskProfile::query()
            ->withoutGlobalScopes()
            ->firstWhere('organization_id', $organization->getKey());

        if ($profile === null || ! $profile->requiresSecondApprover()) {
            return null;
        }

        return sprintf(
            'This client is %s risk (%d/100), so a financial action on it needs a second approver.',
            strtolower($profile->level->label()),
            $profile->score,
        );
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
