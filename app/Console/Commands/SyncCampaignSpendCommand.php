<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Campaign\Jobs\SyncCampaignSpend;
use App\Domains\Campaign\Models\Campaign;
use Illuminate\Console\Command;

/**
 * Queues a spend sync for every live campaign (spec §32, §78).
 *
 * Selects and queues; the provider calls happen on the `campaign_sync` queue.
 * Doing the work inline would leave a schedule tick blocked behind whichever
 * provider is slowest.
 */
final class SyncCampaignSpendCommand extends Command
{
    protected $signature = 'ads:sync-campaign-spend {--provider= : Limit to one provider}';

    protected $description = 'Queue spend reconciliation for running campaigns';

    public function handle(): int
    {
        $query = Campaign::query()
            ->withoutGlobalScopes()
            ->live()
            ->whereNotNull('provider_campaign_id');

        if (is_string($provider = $this->option('provider')) && $provider !== '') {
            $query->where('provider', strtoupper($provider));
        }

        $queued = 0;

        $query->orderBy('id')->chunkById(200, function ($campaigns) use (&$queued): void {
            foreach ($campaigns as $campaign) {
                SyncCampaignSpend::dispatch($campaign->getKey());
                $queued++;
            }
        });

        $this->info("Queued {$queued} campaign spend syncs.");

        return self::SUCCESS;
    }
}
