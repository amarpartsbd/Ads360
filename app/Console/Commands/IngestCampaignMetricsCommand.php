<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Analytics\Jobs\IngestCampaignMetrics;
use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Models\Campaign;
use Illuminate\Console\Command;

/**
 * Queues a metrics pull for every campaign worth reading (spec §38).
 *
 * Completed campaigns are included, and deliberately: a provider restates a
 * day for a while after it ends, so a campaign that stopped yesterday still
 * has figures arriving. They drop out once their end date is far enough back
 * that no attribution window is still open.
 */
final class IngestCampaignMetricsCommand extends Command
{
    protected $signature = 'ads:ingest-metrics
                            {--days= : Override how many days back to re-read}
                            {--campaign= : One campaign, by public id}';

    protected $description = 'Queue daily performance ingestion for campaigns';

    public function handle(): int
    {
        $lookback = $this->option('days') === null ? null : (int) $this->option('days');

        $query = Campaign::query()
            ->withoutGlobalScopes()
            ->whereNotNull('provider_campaign_id');

        if (is_string($campaign = $this->option('campaign')) && $campaign !== '') {
            $query->where('public_id', $campaign);
        } else {
            $query->where(fn ($inner) => $inner
                ->whereIn('status', [CampaignStatus::Active, CampaignStatus::Paused])
                // Recently finished campaigns keep being read while their
                // attribution windows are still open.
                ->orWhere(fn ($recent) => $recent
                    ->where('status', CampaignStatus::Completed)
                    ->where('completed_at', '>=', now()->subDays(14))));
        }

        $queued = 0;

        $query->orderBy('id')->chunkById(200, function ($campaigns) use (&$queued, $lookback): void {
            foreach ($campaigns as $campaign) {
                IngestCampaignMetrics::dispatch($campaign->getKey(), $lookback);
                $queued++;
            }
        });

        $this->info("Queued {$queued} metrics ingestions.");

        return self::SUCCESS;
    }
}
