<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Compliance\Exceptions\IncompleteSubmission;
use App\Domains\Compliance\Exceptions\InvalidVerificationTransition;
use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Tenant\Enums\OrganizationStatus;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A client submits their business for verification (spec §11).
 *
 * Submitting is the client's last word on the details: once it is in the queue
 * they cannot edit it, because a reviewer looking at a document must be sure
 * the declaration beside it has not moved underneath them.
 */
final class SubmitVerification
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws IncompleteSubmission when required evidence is missing
     * @throws InvalidVerificationTransition when the profile is not in a submittable state
     */
    public function handle(Organization $organization, array $attributes): VerificationProfile
    {
        return DB::transaction(function () use ($organization, $attributes): VerificationProfile {
            $profile = $this->lockedProfileFor($organization);

            if (! $profile->status->canTransitionTo(VerificationStatus::Pending)) {
                throw InvalidVerificationTransition::between($profile->status, VerificationStatus::Pending);
            }

            $before = AuditRecorder::snapshot($profile);

            $profile->fill($attributes);
            $profile->save();

            // Documents are uploaded before submission, so completeness is
            // checked against what is actually attached.
            $profile->load('documents');

            $missing = $profile->missingRequiredDocuments();

            if ($missing !== []) {
                throw IncompleteSubmission::missingDocuments($missing);
            }

            $profile->forceFill([
                'status' => VerificationStatus::Pending,
                'submitted_at' => Carbon::now(),
                // A resubmission supersedes the previous outcome, so the stale
                // reviewer message is cleared rather than left to confuse.
                'client_message' => null,
                'reviewed_at' => null,
                'reviewed_by' => null,
            ])->save();

            $this->moveOrganizationUnderReview($organization);

            $this->audit->recordChange(
                action: AuditAction::VerificationSubmitted,
                resource: $profile,
                before: $before,
                context: ['documents' => $profile->documents->count()],
                organization: $organization,
            );

            return $profile;
        });
    }

    /**
     * The organization's profile, created on first use and locked for the
     * duration of the transaction so two submissions cannot race.
     */
    private function lockedProfileFor(Organization $organization): VerificationProfile
    {
        $profile = VerificationProfile::query()
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->first();

        if ($profile !== null) {
            return $profile;
        }

        $profile = new VerificationProfile;
        $profile->organization_id = $organization->getKey();
        $profile->tenant_id = $organization->tenant_id;
        $profile->status = VerificationStatus::NotSubmitted;

        return $profile;
    }

    /**
     * A pending organization moves to under review while compliance looks at
     * it. An organization that is already active — a resubmission after
     * suspension, say — keeps its current state; only a reviewer changes that.
     */
    private function moveOrganizationUnderReview(Organization $organization): void
    {
        if ($organization->status !== OrganizationStatus::Pending) {
            return;
        }

        $organization->forceFill(['status' => OrganizationStatus::UnderReview])->save();
    }
}
