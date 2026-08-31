<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Exceptions\CampaignException;
use App\Domains\Campaign\Exceptions\IncompleteCampaign;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Campaign\Services\CampaignCosting;
use App\Domains\Campaign\Services\CampaignReadiness;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sends a campaign for review (spec §21, §22).
 *
 * Two things happen here that cannot happen anywhere else.
 *
 * The campaign is checked for completeness *before* any money is involved, so
 * a client learns their campaign cannot run while it still costs nothing to
 * fix. Everything wrong is reported at once.
 *
 * And the price is frozen. The pricing engine quotes the total, and that quote
 * — fees, tax, the plan it came from — is written onto the campaign. A plan
 * that changes tomorrow does not change what this client agreed to today, and
 * the reviewer approving it is approving a figure that cannot move underneath
 * them.
 */
final class SubmitCampaign
{
    public function __construct(
        private readonly CampaignReadiness $readiness,
        private readonly CampaignCosting $costing,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Campaign $campaign, User $actor): Campaign
    {
        if (! $campaign->status->canTransitionTo(CampaignStatus::PendingReview)) {
            throw CampaignException::invalidTransition($campaign->status, CampaignStatus::PendingReview);
        }

        $reasons = $this->readiness->reasonsNotReady($campaign);

        if ($reasons !== []) {
            throw IncompleteCampaign::because($reasons);
        }

        $quote = $this->costing->quote($campaign);
        $before = AuditRecorder::snapshot($campaign);

        DB::transaction(function () use ($campaign, $quote, $actor): void {
            $campaign->status = CampaignStatus::PendingReview;
            $campaign->submitted_at = Carbon::now();
            $campaign->submitted_by = $actor->getKey();

            // Frozen here and read back at approval. Nothing recomputes it.
            $campaign->fee_total = $quote->feeTotal->minorUnits;
            $campaign->charged_total = $quote->total->minorUnits;
            $campaign->pricing_snapshot = $quote->toArray();

            // A resubmission after changes were requested starts with a clean
            // slate; the previous notes are in the audit log.
            $campaign->review_notes = null;
            $campaign->reviewed_at = null;
            $campaign->reviewed_by = null;

            $campaign->save();
        });

        $this->audit->recordChange(
            action: AuditAction::CampaignSubmitted,
            resource: $campaign,
            before: $before,
            context: [
                'budget' => $campaign->budget()->toDecimal(),
                'charged_total' => $campaign->chargedTotal()->toDecimal(),
                'currency' => $campaign->currency,
            ],
            actor: $actor,
        );

        return $campaign;
    }
}
