<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\PerformanceTotals;
use App\Domains\Analytics\Models\CampaignDailyMetric;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Tenant\Models\Organization;
use App\Support\Values\Money;
use Illuminate\Support\Carbon;

/**
 * Reads the metrics tables (spec §38, §39).
 *
 * Every aggregation happens in the database and every derived figure in PHP.
 * Nothing is sent to a browser that a browser then has to add up: a client
 * looking at a total and the platform looking at the same total must see the
 * same number, and two places computing it is two places for them to differ
 * (Rule 8).
 *
 * All reads are campaign-level rows only. A campaign, its ad sets and its ads
 * each get their own rows, and summing across levels would count the same
 * spend two or three times.
 */
final class AnalyticsQuery
{
    /** Totals for one organization over a window. */
    public function totalsForOrganization(
        Organization $organization,
        Carbon $from,
        Carbon $to,
        ?string $currency = null,
    ): PerformanceTotals {
        $currency ??= $organization->default_currency;

        $row = CampaignDailyMetric::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('currency', $currency)
            ->campaignLevel()
            ->between($from, $to)
            ->selectRaw($this->sumSelect())
            ->first();

        return $this->totalsFrom($row, $currency);
    }

    public function totalsForCampaign(Campaign $campaign, Carbon $from, Carbon $to): PerformanceTotals
    {
        $row = CampaignDailyMetric::query()
            ->withoutGlobalScopes()
            ->where('campaign_id', $campaign->getKey())
            ->campaignLevel()
            ->between($from, $to)
            ->selectRaw($this->sumSelect())
            ->first();

        return $this->totalsFrom($row, $campaign->currency);
    }

    /**
     * A day-by-day series for a chart.
     *
     * Every day in the window is present, including days with no data. A chart
     * drawn only from days that exist would join Friday to Monday with a
     * straight line and imply a weekend that never happened.
     *
     * @return list<array<string, mixed>>
     */
    public function dailySeries(
        Organization $organization,
        Carbon $from,
        Carbon $to,
        ?string $currency = null,
    ): array {
        $currency ??= $organization->default_currency;

        $rows = CampaignDailyMetric::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('currency', $currency)
            ->campaignLevel()
            ->between($from, $to)
            ->groupBy('metric_date')
            ->orderBy('metric_date')
            ->selectRaw('metric_date, '.$this->sumSelect())
            ->get()
            ->keyBy(fn (CampaignDailyMetric $metric): string => $metric->metric_date->toDateString());

        $series = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);

            $totals = $row === null
                ? PerformanceTotals::empty($currency)
                : $this->totalsFrom($row, $currency);

            $series[] = [
                'date' => $key,
                'spendMinor' => $totals->spend->minorUnits,
                'spend' => $totals->spend->format(),
                'impressions' => $totals->impressions,
                'clicks' => $totals->clicks,
                'conversions' => $totals->conversions,
                // Says whether the day is genuinely empty or simply absent —
                // a chart should be able to tell the difference.
                'reported' => $row !== null,
            ];

            $cursor->addDay();
        }

        return $series;
    }

    /**
     * Per-campaign totals for a table, biggest spender first.
     *
     * @return list<array<string, mixed>>
     */
    public function campaignBreakdown(
        Organization $organization,
        Carbon $from,
        Carbon $to,
        int $limit = 50,
    ): array {
        $rows = CampaignDailyMetric::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->campaignLevel()
            ->between($from, $to)
            ->groupBy('campaign_id', 'currency')
            ->orderByDesc('total_spend')
            ->limit($limit)
            ->selectRaw('campaign_id, currency, '.$this->sumSelect())
            ->get();

        $campaigns = Campaign::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $rows->pluck('campaign_id'))
            ->get()
            ->keyBy('id');

        return $rows
            ->map(function (CampaignDailyMetric $row) use ($campaigns): ?array {
                $campaign = $campaigns->get($row->campaign_id);

                if ($campaign === null) {
                    return null;
                }

                return [
                    'id' => $campaign->public_id,
                    'name' => $campaign->name,
                    'status' => $campaign->status->value,
                    'statusLabel' => $campaign->status->label(),
                    ...$this->totalsFrom($row, $row->currency)->toArray(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The same window, one period earlier — for "up 12% on the previous
     * fortnight" without the browser working out what the previous fortnight
     * was.
     *
     * @return array{from: Carbon, to: Carbon}
     */
    public function precedingWindow(Carbon $from, Carbon $to): array
    {
        // Inclusive of both ends, so a seven-day window compares against the
        // seven days before it rather than six.
        $days = $from->diffInDays($to) + 1;

        return [
            'from' => $from->copy()->subDays($days),
            'to' => $from->copy()->subDay(),
        ];
    }

    /**
     * The currencies an organization actually has figures in. A client billed
     * in one currency should not be offered a filter for another.
     *
     * @return list<string>
     */
    public function currenciesFor(Organization $organization): array
    {
        return CampaignDailyMetric::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency')
            ->all();
    }

    /** The aggregate columns, named so they can be read back consistently. */
    private function sumSelect(): string
    {
        return implode(', ', [
            'COALESCE(SUM(spend), 0) AS total_spend',
            'COALESCE(SUM(impressions), 0) AS total_impressions',
            'COALESCE(SUM(clicks), 0) AS total_clicks',
            'COALESCE(SUM(reach), 0) AS total_reach',
            'COALESCE(SUM(conversions), 0) AS total_conversions',
            'COALESCE(SUM(conversion_value), 0) AS total_conversion_value',
        ]);
    }

    private function totalsFrom(?object $row, string $currency): PerformanceTotals
    {
        if ($row === null) {
            return PerformanceTotals::empty($currency);
        }

        return new PerformanceTotals(
            spend: Money::ofMinor((int) ($row->total_spend ?? 0), $currency),
            impressions: (int) ($row->total_impressions ?? 0),
            clicks: (int) ($row->total_clicks ?? 0),
            reach: (int) ($row->total_reach ?? 0),
            conversions: (int) ($row->total_conversions ?? 0),
            conversionValue: Money::ofMinor((int) ($row->total_conversion_value ?? 0), $currency),
        );
    }
}
