<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Compliance\Enums\ReviewDecision;
use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Compliance\Exceptions\InvalidVerificationTransition;
use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Compliance\Models\VerificationReview;
use App\Domains\Compliance\Notifications\VerificationOutcome;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\OrganizationStatus;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A compliance reviewer decides on a verification submission (spec §11).
 *
 * Every decision does four things atomically: it records an immutable review
 * row, moves the profile through a validated transition, brings the
 * organization's own status into line, and writes an audit entry. None of those
 * may happen without the others, which is why they share a transaction.
 */
final class ReviewVerification
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  list<string>  $referencedDocuments  public ids of documents the decision refers to
     *
     * @throws InvalidVerificationTransition
     */
    public function handle(
        VerificationProfile $profile,
        User $reviewer,
        ReviewDecision $decision,
        ?string $clientMessage = null,
        ?string $internalNote = null,
        array $referencedDocuments = [],
    ): VerificationReview {
        if ($decision->requiresClientMessage() && trim((string) $clientMessage) === '') {
            throw new InvalidArgumentException(
                "A [{$decision->value}] decision must include a message explaining it to the client."
            );
        }

        return DB::transaction(function () use (
            $profile,
            $reviewer,
            $decision,
            $clientMessage,
            $internalNote,
            $referencedDocuments,
        ): VerificationReview {
            /** @var VerificationProfile $locked */
            $locked = VerificationProfile::query()
                ->whereKey($profile->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $from = $locked->status;
            $to = $decision->resultingStatus();

            if (! $from->canTransitionTo($to)) {
                throw InvalidVerificationTransition::between($from, $to);
            }

            $review = new VerificationReview([
                'verification_profile_id' => $locked->getKey(),
                'organization_id' => $locked->organization_id,
                'reviewer_id' => $reviewer->getKey(),
                'decision' => $decision,
                'from_status' => $from,
                'to_status' => $to,
                'internal_note' => $internalNote,
                'client_message' => $clientMessage,
                'referenced_documents' => array_values($referencedDocuments),
            ]);
            $review->tenant_id = $locked->tenant_id;
            $review->save();

            $locked->forceFill([
                'status' => $to,
                'reviewed_at' => Carbon::now(),
                'reviewed_by' => $reviewer->getKey(),
                // Only the client-facing message is carried onto the profile.
                // The internal note stays in the review history.
                'client_message' => $clientMessage,
            ])->save();

            $organization = $locked->organization()->firstOrFail();
            $this->syncOrganizationStatus($organization, $to);

            $this->audit->record(
                action: $this->auditActionFor($decision),
                resource: $locked,
                before: ['status' => $from->value],
                after: ['status' => $to->value],
                // The internal note is deliberately not audited as free text;
                // it lives in the review row, which is itself append-only.
                context: ['decision' => $decision->value, 'documents' => $referencedDocuments],
                organization: $organization,
                actor: $reviewer,
            );

            $this->notifyClient($organization, $decision, $clientMessage);

            return $review;
        });
    }

    /**
     * Keep the organization's own status consistent with the verification
     * outcome, so nothing has to join to the profile to know whether an
     * organization may transact.
     */
    private function syncOrganizationStatus(Organization $organization, VerificationStatus $status): void
    {
        $target = match ($status) {
            VerificationStatus::Verified => OrganizationStatus::Active,
            VerificationStatus::UnderReview => OrganizationStatus::UnderReview,
            VerificationStatus::Suspended => OrganizationStatus::Suspended,
            // A rejection or an information request leaves the organization
            // pending: the client can still correct and resubmit.
            VerificationStatus::Rejected,
            VerificationStatus::RequiresInformation => OrganizationStatus::Pending,
            default => null,
        };

        if ($target === null || $organization->status === $target) {
            return;
        }

        $organization->forceFill([
            'status' => $target,
            'activated_at' => $target === OrganizationStatus::Active
                ? ($organization->activated_at ?? Carbon::now())
                : $organization->activated_at,
            'suspended_at' => $target === OrganizationStatus::Suspended ? Carbon::now() : null,
        ])->save();
    }

    private function auditActionFor(ReviewDecision $decision): AuditAction
    {
        return match ($decision) {
            ReviewDecision::Claimed => AuditAction::VerificationClaimed,
            ReviewDecision::Approved => AuditAction::ClientVerificationApproved,
            ReviewDecision::Rejected => AuditAction::ClientVerificationRejected,
            ReviewDecision::InformationRequested => AuditAction::VerificationInformationRequested,
            ReviewDecision::Suspended => AuditAction::VerificationSuspended,
        };
    }

    /**
     * Tell the organization's owners what happened. Claiming a case for review
     * is internal bookkeeping and is not announced.
     */
    private function notifyClient(
        Organization $organization,
        ReviewDecision $decision,
        ?string $clientMessage,
    ): void {
        if ($decision === ReviewDecision::Claimed) {
            return;
        }

        $recipients = $organization->activeMembers()->get();

        foreach ($recipients as $member) {
            $member->notify(new VerificationOutcome($organization, $decision, $clientMessage));
        }
    }
}
