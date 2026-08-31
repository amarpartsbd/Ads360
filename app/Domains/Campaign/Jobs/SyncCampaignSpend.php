<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Jobs;

use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Campaign\Services\CampaignSpendReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Brings one campaign's spend up to date and finishes it when its run is over
 * (spec §32, §78).
 *
 * One campaign per job, so a provider that is slow for one account does not
 * hold up everyone else's reconciliation.
 */
final class SyncCampaignSpend implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> seconds to wait before each retry */
    public array $backoff = [60, 300];

    public function __construct(private readonly int $campaignId)
    {
        $this->onQueue('campaign_sync');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        // Two syncs at once would both read the same "already captured"
        // figure and both capture the same delta. The ledger's own locking
        // would keep the arithmetic sound, but the client would be charged
        // twice for one period.
        return [(new WithoutOverlapping((string) $this->campaignId))->dontRelease()];
    }

    public function handle(CampaignSpendReconciler $reconciler): void
    {
        $campaign = Campaign::query()->withoutGlobalScopes()->find($this->campaignId);

        if ($campaign === null || ! $campaign->status->isLive()) {
            return;
        }

        try {
            $campaign = $reconciler->sync($campaign);
        } catch (ProviderUnavailable $exception) {
            // A provider that cannot be reached tells us nothing about spend.
            // The stored figures stay as they were and the job retries.
            if ($exception->retryable) {
                throw $exception;
            }

            return;
        }

        if ($this->hasFinished($campaign)) {
            $reconciler->complete($campaign, 'Campaign reached its end date');
        }
    }

    /**
     * A campaign is done when its schedule is over. Spend reaching the budget
     * is deliberately not the test: providers settle auctions after the fact,
     * and closing early would strand the last of the spend outside the hold.
     */
    private function hasFinished(Campaign $campaign): bool
    {
        return $campaign->ends_at !== null && $campaign->ends_at->isPast();
    }
}
