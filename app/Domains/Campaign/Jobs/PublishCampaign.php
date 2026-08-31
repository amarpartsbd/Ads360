<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Jobs;

use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Campaign\Services\CampaignPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Publishes an approved campaign (spec §21, Rule 16, Rule 17).
 *
 * Queued rather than done in the approval request, because publishing is a
 * chain of provider calls and an approver should not be watching a spinner
 * while it happens.
 *
 * Retrying is expected and safe. Every provider call is claimed in the
 * publication ledger first, so a retry either skips work that succeeded or
 * resumes it with the key it started under. What a retry must never do is
 * create a second campaign, and that is guaranteed by a unique index rather
 * than by this job being careful.
 */
final class PublishCampaign implements ShouldQueue
{
    use Queueable;

    /** Provider calls fail for boring reasons; the backoff gives them room. */
    public int $tries = 4;

    public array $backoff = [30, 120, 600];

    public function __construct(private readonly int $campaignId)
    {
        $this->onQueue('campaign_publish');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        // Two workers publishing one campaign would each claim different
        // entities in the same chain and interleave their writes. The ledger
        // would still prevent duplicates, but the campaign would end up half
        // published by each — so they are kept apart.
        return [(new WithoutOverlapping((string) $this->campaignId))->releaseAfter(60)];
    }

    public function handle(CampaignPublisher $publisher, AuditRecorder $audit): void
    {
        $campaign = Campaign::query()->withoutGlobalScopes()->find($this->campaignId);

        if ($campaign === null) {
            return;
        }

        // Approved is the normal entry; Publishing means a previous attempt
        // was interrupted; Failed means an operator or a retry sent it again.
        if (! in_array($campaign->status, [
            CampaignStatus::Approved,
            CampaignStatus::Publishing,
            CampaignStatus::Failed,
        ], true)) {
            return;
        }

        try {
            $publisher->publish($campaign);
        } catch (ProviderUnavailable $exception) {
            $this->recordFailure($campaign, $audit, $exception);

            // Rethrown so the queue applies the backoff. A retryable failure
            // is genuinely worth another go; a refusal is not, and the job
            // stops after its final attempt either way.
            if ($exception->retryable) {
                throw $exception;
            }
        }
    }

    /**
     * The campaign keeps its reservation and its ad account. Releasing them on
     * a failed publish would mean a client whose campaign failed at midnight
     * loses their held budget to another campaign before anyone can retry.
     */
    private function recordFailure(
        Campaign $campaign,
        AuditRecorder $audit,
        ProviderUnavailable $exception,
    ): void {
        if ($campaign->status->canTransitionTo(CampaignStatus::Failed)) {
            $campaign->status = CampaignStatus::Failed;
        }

        // The adapter's client-safe text, never a provider error code (§80).
        $campaign->last_error = mb_substr($exception->clientMessage, 0, 250);
        $campaign->save();

        $audit->recordSystemEvent(
            action: AuditAction::CampaignPublishFailed,
            resource: $campaign,
            context: [
                'provider' => $campaign->provider->value,
                'retryable' => $exception->retryable,
                'reason' => $exception->clientMessage,
            ],
            label: 'PublishCampaign',
        );
    }
}
