<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Enums;

/**
 * The reports a client can ask for (spec §39).
 *
 * A closed set, not a query builder. An export that let a client describe an
 * arbitrary query would be a way to ask the database questions nobody
 * reviewed — and the answers would be about their own data only if every one
 * of those questions was scoped correctly.
 */
enum ReportType: string
{
    case CampaignPerformance = 'CAMPAIGN_PERFORMANCE';
    case DailyPerformance = 'DAILY_PERFORMANCE';
    case SpendStatement = 'SPEND_STATEMENT';

    public function label(): string
    {
        return match ($this) {
            self::CampaignPerformance => 'Performance by campaign',
            self::DailyPerformance => 'Performance by day',
            self::SpendStatement => 'Spend statement',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CampaignPerformance => 'One row per campaign, with totals for the period.',
            self::DailyPerformance => 'One row per day, across all campaigns.',
            self::SpendStatement => 'What was spent and what it was charged against, per campaign.',
        };
    }

    /**
     * The header row. Defined here so the file's shape and the code that
     * fills it cannot drift apart.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return match ($this) {
            self::CampaignPerformance => [
                'Campaign', 'Status', 'Currency', 'Spend', 'Impressions', 'Clicks',
                'Click-through rate (%)', 'Conversions', 'Cost per conversion',
            ],
            self::DailyPerformance => [
                'Date', 'Currency', 'Spend', 'Impressions', 'Clicks', 'Conversions',
            ],
            self::SpendStatement => [
                'Campaign', 'Currency', 'Budget', 'Total charged', 'Charged to date',
                'Provider reported spend', 'Status',
            ],
        };
    }

    public function filename(): string
    {
        return match ($this) {
            self::CampaignPerformance => 'campaign-performance',
            self::DailyPerformance => 'daily-performance',
            self::SpendStatement => 'spend-statement',
        };
    }
}
