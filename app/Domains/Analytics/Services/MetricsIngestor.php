<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Advertising\DTOs\DailyInsightRow;
use App\Domains\Advertising\Enums\ProviderCapability;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Services\ProviderManager;
use App\Domains\Analytics\Models\CampaignDailyMetric;
use App\Domains\Campaign\Models\Campaign;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Brings a campaign's daily figures up to date (spec §38, §78).
 *
 * The whole design turns on one fact about advertising platforms: **they
 * restate**. A conversion attributed three days after the click lands on the
 * day of the click, changing a figure the platform already reported. Spend
 * settles late as auctions clear. A day is not final when it ends.
 *
 * Two consequences, and both are deliberate:
 *
 *   - **A trailing window is re-fetched, not just yesterday.** Asking only
 *     about the newest day would leave every restatement of an older one
 *     permanently wrong.
 *   - **Rows are upserted, never appended.** Re-fetching a day the platform
 *     already has must replace it. A pipeline that inserted would show a
 *     client the same day several times and a total that grew every hour.
 *
 * Nothing here touches money. These figures are what a client is *shown*;
 * what a client is *charged* comes from the reservation and the ledger, and
 * the two are compared by the reconciler rather than one being derived from
 * the other.
 */
final class MetricsIngestor
{
    /**
     * How far back each run re-reads.
     *
     * Long enough to catch the attribution windows the major providers use,
     * short enough that a daily sweep over every live campaign stays cheap.
     */
    private const DEFAULT_LOOKBACK_DAYS = 7;

    public function __construct(private readonly ProviderManager $providers) {}

    /**
     * @return int the number of days written
     *
     * @throws ProviderUnavailable
     */
    public function ingest(Campaign $campaign, ?int $lookbackDays = null): int
    {
        if ($campaign->provider_campaign_id === null) {
            return 0;
        }

        $account = $campaign->adAccount()->withoutGlobalScopes()->first();

        if ($account === null || ! $this->providers->isAvailable($campaign->provider)) {
            return 0;
        }

        $adapter = $this->providers->for($campaign->provider);

        if (! $adapter->supports(ProviderCapability::MetricsRetrieval)) {
            return 0;
        }

        [$since, $until] = $this->window($campaign, $lookbackDays ?? self::DEFAULT_LOOKBACK_DAYS);

        $rows = $adapter->campaignDailyInsights(
            $account,
            $campaign->provider_campaign_id,
            $since,
            $until,
        );

        return $this->store($campaign, $rows);
    }

    /**
     * Write the rows, replacing whatever was there for those days.
     *
     * @param  list<DailyInsightRow>  $rows
     */
    public function store(Campaign $campaign, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $now = Carbon::now();
        $written = 0;

        DB::transaction(function () use ($campaign, $rows, $now, &$written): void {
            foreach ($rows as $row) {
                $values = $this->valuesFor($row, $campaign, $now);

                if ($values === null) {
                    continue;
                }

                /*
                 * firstOrNew rather than updateOrCreate: the tenant is
                 * deliberately not mass-assignable, so it is stamped from the
                 * campaign explicitly. The unique index is still what
                 * guarantees one row per day; this is the path that uses it.
                 */
                $metric = CampaignDailyMetric::query()
                    ->withoutGlobalScopes()
                    ->firstOrNew([
                        'campaign_id' => $campaign->getKey(),
                        'ad_set_id' => null,
                        'ad_id' => null,
                        'metric_date' => $row->date->format('Y-m-d'),
                    ]);

                $metric->tenant_id = $campaign->tenant_id;
                $metric->forceFill($values)->save();

                $written++;
            }
        });

        return $written;
    }

    /**
     * The values to write for one day.
     *
     * A row reporting nothing at all is skipped rather than written as zeroes.
     * Zeroes would tell a client their campaign ran and achieved nothing on a
     * day the provider simply had not finished counting (§87).
     *
     * @return array<string, mixed>|null
     */
    private function valuesFor(DailyInsightRow $row, Campaign $campaign, Carbon $now): ?array
    {
        $reported = array_filter(
            [
                'spend' => $row->spendMinor,
                'impressions' => $row->impressions,
                'clicks' => $row->clicks,
                'reach' => $row->reach,
                'conversions' => $row->conversions,
                'conversion_value' => $row->conversionValueMinor,
            ],
            static fn (?int $value): bool => $value !== null,
        );

        if ($reported === []) {
            return null;
        }

        return [
            'organization_id' => $campaign->organization_id,
            'provider' => $campaign->provider,
            // The provider's currency for the campaign, which is the campaign's
            // own — allocation refuses to put them in different ones.
            'currency' => $row->currency ?? $campaign->currency,
            'reported_at' => $now,
            // Anything the provider did not report keeps its column default of
            // zero on a new row, and its previous value on an existing one.
            ...array_map(static fn (int $value): int => max(0, $value), $reported),
        ];
    }

    /**
     * The window to re-read.
     *
     * Never starts before the campaign did — asking a provider about days
     * before a campaign existed wastes a call and returns nothing — and never
     * ends after today, because a provider has nothing to say about tomorrow.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function window(Campaign $campaign, int $lookbackDays): array
    {
        $today = Carbon::now()->startOfDay();

        $since = $today->copy()->subDays(max(0, $lookbackDays));

        if ($campaign->starts_at !== null) {
            $campaignStart = Carbon::instance($campaign->starts_at->toDateTime())->startOfDay();

            if ($campaignStart->greaterThan($since)) {
                $since = $campaignStart;
            }
        }

        $until = $today;

        if ($campaign->ends_at !== null) {
            $campaignEnd = Carbon::instance($campaign->ends_at->toDateTime())->startOfDay();

            if ($campaignEnd->lessThan($until)) {
                $until = $campaignEnd;
            }
        }

        // A campaign scheduled entirely in the future has no window at all;
        // clamping keeps the range valid rather than inverted.
        if ($since->greaterThan($until)) {
            $since = $until->copy();
        }

        return [
            new DateTimeImmutable($since->toDateString()),
            new DateTimeImmutable($until->toDateString()),
        ];
    }
}
