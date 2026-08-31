<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Jobs;

use App\Domains\Analytics\Services\SpendReconciler;
use App\Domains\Campaign\Models\Campaign;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Compares one campaign's provider spend against the ledger (spec §78).
 *
 * Reads only: it writes a comparison and, past a tolerance, raises it. No
 * balance is touched here.
 */
final class ReconcileCampaignSpend implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(private readonly int $campaignId)
    {
        $this->onQueue('analytics');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->campaignId))->dontRelease()];
    }

    public function handle(SpendReconciler $reconciler): void
    {
        $campaign = Campaign::query()->withoutGlobalScopes()->find($this->campaignId);

        if ($campaign === null) {
            return;
        }

        $reconciler->reconcile($campaign);
    }
}
