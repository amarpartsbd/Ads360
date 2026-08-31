<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Exceptions\CampaignException;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Campaign\Notifications\CampaignReviewed;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a campaign down, or sends it back for changes (spec §21).
 *
 * Nothing is released, because nothing was held: the budget is reserved at
 * approval, not at submission. That ordering is deliberate — a campaign
 * waiting in a review queue should not be freezing a client's balance.
 *
 * The two outcomes differ in what the client can do next. Changes requested
 * returns the campaign to their hands to edit and resubmit; rejected is final,
 * and the client copies it if they want to try again. A rejected campaign is
 * never quietly reopened, so the record of what was refused stays intact.
 */
final class RejectCampaign
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** Final. The reason is shown to the client, so it is written for them. */
    public function reject(Campaign $campaign, User $reviewer, string $reason): Campaign
    {
        return $this->settle($campaign, $reviewer, $reason, CampaignStatus::Rejected);
    }

    /** Recoverable: the client edits and submits again. */
    public function requestChanges(Campaign $campaign, User $reviewer, string $notes): Campaign
    {
        return $this->settle($campaign, $reviewer, $notes, CampaignStatus::ChangesRequested);
    }

    private function settle(
        Campaign $campaign,
        User $reviewer,
        string $notes,
        CampaignStatus $outcome,
    ): Campaign {
        if ($campaign->status !== CampaignStatus::PendingReview) {
            throw CampaignException::notUnderReview();
        }

        if ($campaign->submitted_by !== null && $campaign->submitted_by === $reviewer->getKey()) {
            throw CampaignException::cannotReviewOwnSubmission();
        }

        if (! $campaign->status->canTransitionTo($outcome)) {
            throw CampaignException::invalidTransition($campaign->status, $outcome);
        }

        $before = AuditRecorder::snapshot($campaign);

        DB::transaction(function () use ($campaign, $reviewer, $notes, $outcome): void {
            $campaign->status = $outcome;
            $campaign->reviewed_at = Carbon::now();
            $campaign->reviewed_by = $reviewer->getKey();
            $campaign->review_notes = trim($notes);
            $campaign->save();
        });

        $this->audit->recordChange(
            action: $outcome === CampaignStatus::Rejected
                ? AuditAction::CampaignRejected
                : AuditAction::CampaignChangesRequested,
            resource: $campaign,
            before: $before,
            context: ['notes' => trim($notes)],
            actor: $reviewer,
        );

        $this->notify($campaign, $outcome);

        return $campaign;
    }

    private function notify(Campaign $campaign, CampaignStatus $outcome): void
    {
        $organization = Organization::query()
            ->withoutGlobalScopes()
            ->whereKey($campaign->organization_id)
            ->first();

        foreach ($organization?->activeMembers()->get() ?? [] as $member) {
            $member->notify(new CampaignReviewed($campaign, $outcome));
        }
    }
}
