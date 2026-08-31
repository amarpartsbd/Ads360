<?php

declare(strict_types=1);

namespace App\Domains\Client\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Client\Models\OrganizationRiskProfile;
use App\Domains\Client\Services\RiskAssessor;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What a person does about a risk profile (spec §12).
 *
 * Three decisions, all of them a human's:
 *
 *   - **flag** — a compliance officer says something is wrong. This is the
 *     only input to the score that is not computed, and it survives every
 *     reassessment until someone clears it.
 *   - **clear** — the flag comes off, with a reason.
 *   - **review** — the account has been looked at and needs nothing further.
 *     It leaves the queue until its level changes.
 *
 * None of them suspends anything. Suspension lives on the organization and
 * stays a separate, deliberate act by someone with the permission for it — §12
 * is explicit that a score must never withdraw financial access on its own.
 */
final class ReviewRiskProfile
{
    public function __construct(
        private readonly RiskAssessor $assessor,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Raise a compliance flag.
     *
     * The reason is required and stored. A flag adds a fifth of the whole
     * scale, so an unexplained one would be twenty points nobody could account
     * for — which is exactly what §12's explainability requirement forbids.
     */
    public function flag(Organization $organization, User $actor, string $reason): OrganizationRiskProfile
    {
        return DB::transaction(function () use ($organization, $actor, $reason): OrganizationRiskProfile {
            $profile = $this->profileFor($organization);

            $profile->forceFill([
                'manual_flag' => true,
                'manual_flag_reason' => trim($reason),
                'manual_flag_by' => $actor->getKey(),
                'manual_flagged_at' => Carbon::now(),
                // A new flag is new information, so any earlier "looked at,
                // nothing to do" no longer applies.
                'reviewed_at' => null,
                'reviewed_by' => null,
            ])->save();

            // Rescored immediately: the flag is worth points, and leaving the
            // score stale until the next sweep would show a queue that
            // disagrees with itself.
            $rescored = $this->assessor->record($organization);

            $this->audit->record(
                action: AuditAction::RiskFlagRaised,
                resource: $rescored,
                after: ['score' => $rescored->score, 'level' => $rescored->level->value],
                context: ['reason' => $reason],
                organization: $organization,
                actor: $actor,
            );

            return $rescored;
        });
    }

    public function clearFlag(Organization $organization, User $actor, string $reason): OrganizationRiskProfile
    {
        return DB::transaction(function () use ($organization, $actor, $reason): OrganizationRiskProfile {
            $profile = $this->profileFor($organization);

            $profile->forceFill([
                'manual_flag' => false,
                /*
                 * The reason and who raised it are kept. Clearing a flag is
                 * not the same as it never having existed, and the next person
                 * to look at this account should be able to see that someone
                 * once had a concern (§62).
                 */
                'reviewed_at' => Carbon::now(),
                'reviewed_by' => $actor->getKey(),
                'review_note' => trim($reason),
            ])->save();

            $rescored = $this->assessor->record($organization);

            $this->audit->record(
                action: AuditAction::RiskFlagCleared,
                resource: $rescored,
                after: ['score' => $rescored->score, 'level' => $rescored->level->value],
                context: ['reason' => $reason],
                organization: $organization,
                actor: $actor,
            );

            return $rescored;
        });
    }

    /** Looked at; nothing further needed at this level. */
    public function markReviewed(
        Organization $organization,
        User $actor,
        ?string $note = null,
    ): OrganizationRiskProfile {
        return DB::transaction(function () use ($organization, $actor, $note): OrganizationRiskProfile {
            $profile = $this->profileFor($organization);

            $profile->forceFill([
                'reviewed_at' => Carbon::now(),
                'reviewed_by' => $actor->getKey(),
                'review_note' => $note === null ? null : trim($note),
            ])->save();

            $this->audit->record(
                action: AuditAction::RiskReviewed,
                resource: $profile,
                after: ['score' => $profile->score, 'level' => $profile->level->value],
                context: ['note' => $note],
                organization: $organization,
                actor: $actor,
            );

            return $profile;
        });
    }

    /**
     * The organization's profile, assessed for the first time if it has none.
     *
     * Locked, because a flag and a scheduled reassessment landing together
     * would otherwise race for the same row.
     */
    private function profileFor(Organization $organization): OrganizationRiskProfile
    {
        $profile = OrganizationRiskProfile::query()
            ->withoutGlobalScopes()
            ->lockForUpdate()
            ->firstWhere('organization_id', $organization->getKey());

        return $profile ?? $this->assessor->record($organization);
    }
}
