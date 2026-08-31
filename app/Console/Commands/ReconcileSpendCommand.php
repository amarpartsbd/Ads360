<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Analytics\Jobs\ReconcileCampaignSpend;
use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Models\Campaign;
use Illuminate\Console\Command;

/**
 * Queues a spend comparison for every campaign that has run (spec §78).
 *
 * Includes completed campaigns for a period after they end, because a
 * provider restating a day is exactly the case this is meant to catch — and
 * that happens after the campaign has stopped.
 */
final class ReconcileSpendCommand extends Command
{
    protected $signature = 'ads:reconcile-spend {--campaign= : One campaign, by public id}';

    protected $description = 'Queue provider-versus-ledger spend comparisons';

    public function handle(): int
    {
        $query = Campaign::query()
            ->withoutGlobalScopes()
            ->whereNotNull('provider_campaign_id')
            ->whereNotNull('wallet_reservation_id');

        if (is_string($campaign = $this->option('campaign')) && $campaign !== '') {
            $query->where('public_id', $campaign);
        } else {
            $query->where(fn ($inner) => $inner
                ->whereIn('status', [CampaignStatus::Active, CampaignStatus::Paused])
                ->orWhere(fn ($recent) => $recent
                    ->where('status', CampaignStatus::Completed)
                    ->where('completed_at', '>=', now()->subDays(30))));
        }

        $queued = 0;

        $query->orderBy('id')->chunkById(200, function ($campaigns) use (&$queued): void {
            foreach ($campaigns as $campaign) {
                ReconcileCampaignSpend::dispatch($campaign->getKey());
                $queued++;
            }
        });

        $this->info("Queued {$queued} spend reconciliations.");

        return self::SUCCESS;
    }
}
