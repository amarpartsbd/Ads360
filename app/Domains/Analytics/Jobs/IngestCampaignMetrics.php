<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Jobs;

use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Analytics\Services\MetricsIngestor;
use App\Domains\Campaign\Models\Campaign;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Pulls one campaign's daily figures (spec §38, Rule 16).
 *
 * On the analytics queue rather than a campaign one: these figures are what a
 * client is shown, not what they are charged, so they must never delay
 * publishing or spend capture (spec §28).
 */
final class IngestCampaignMetrics implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> seconds to wait before each retry */
    public array $backoff = [60, 300];

    public function __construct(
        private readonly int $campaignId,
        private readonly ?int $lookbackDays = null,
    ) {
        $this->onQueue('analytics');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        // Two runs against one campaign would upsert the same days from two
        // different reads. The unique index keeps the data sound either way,
        // but the later write could carry the older figures.
        return [(new WithoutOverlapping((string) $this->campaignId))->dontRelease()];
    }

    public function handle(MetricsIngestor $ingestor): void
    {
        $campaign = Campaign::query()->withoutGlobalScopes()->find($this->campaignId);

        if ($campaign === null) {
            return;
        }

        try {
            $ingestor->ingest($campaign, $this->lookbackDays);
        } catch (ProviderUnavailable $exception) {
            // A provider that cannot be reached says nothing about the
            // figures; the stored days stay as they were.
            if ($exception->retryable) {
                throw $exception;
            }
        }
    }
}
