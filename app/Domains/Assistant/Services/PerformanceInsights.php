<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Services;

use App\Domains\Analytics\Services\AnalyticsQuery;
use App\Domains\Assistant\Enums\RecommendationKind;
use App\Domains\Assistant\Enums\RecommendationStatus;
use App\Domains\Assistant\Models\Recommendation;
use App\Domains\Tenant\Models\Organization;
use App\Support\Values\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Performance insights, computed from the client's own figures (spec §47).
 *
 * There is no model here and no assistant. §47's own example — campaign A costs
 * ৳180 a lead, campaign B costs ৳72, consider moving budget — is arithmetic
 * over data the platform already has, and doing it with a language model would
 * make a reproducible fact into an unreproducible opinion while costing money
 * per request.
 *
 * These are recorded as recommendations like any other, with `deterministic` as
 * their driver. A client reading one is entitled to know whether it came from
 * their own numbers or from a model, and this is how they find out.
 *
 * Every insight here is an observation with a suggestion attached. None of them
 * changes anything: §45's rule that output is a recommendation applies just as
 * much to arithmetic as to a model, because a confident wrong number moves a
 * budget just as easily as a confident wrong sentence.
 */
final class PerformanceInsights
{
    /**
     * How much better one campaign has to be before it is worth saying so.
     *
     * Twice as efficient. A ten-percent difference between two campaigns is
     * noise — attribution windows, day of week, one good creative — and a
     * platform that flagged it would train clients to ignore the ones that
     * matter.
     */
    private const MATERIAL_MULTIPLE = 2;

    /**
     * Below this, the comparison is not worth making regardless of ratio.
     *
     * A campaign with three conversions has a cost per conversion that is an
     * accident of which three people happened to convert.
     */
    private const MINIMUM_CONVERSIONS = 10;

    public function __construct(private readonly AnalyticsQuery $analytics) {}

    /**
     * Work out what is worth saying about a window, and store it.
     *
     * Existing open insights for the organization are expired first: an
     * observation about last month's spend shown beside this month's is worse
     * than no observation, because a client cannot tell which window it is
     * about.
     *
     * @return list<Recommendation>
     */
    public function refresh(Organization $organization, Carbon $from, Carbon $to): array
    {
        $found = $this->compute($organization, $from, $to);

        return DB::transaction(function () use ($organization, $found): array {
            Recommendation::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $organization->getKey())
                ->where('kind', RecommendationKind::Insight->value)
                ->where('status', RecommendationStatus::Offered->value)
                ->update([
                    'status' => RecommendationStatus::Expired->value,
                    'updated_at' => Carbon::now(),
                ]);

            $stored = [];

            foreach ($found as $insight) {
                $recommendation = new Recommendation([
                    'organization_id' => $organization->getKey(),
                    'kind' => RecommendationKind::Insight,
                    'headline' => $insight['headline'],
                    'body' => $insight['body'],
                    'payload' => $insight['payload'],
                ]);

                $recommendation->tenant_id = $organization->tenant_id;
                $recommendation->status = RecommendationStatus::Offered;

                // Not a model. Recorded as such, which is the useful fact.
                $recommendation->source_driver = 'deterministic';
                $recommendation->source_model = 'performance-insights';
                $recommendation->source_version = '1';

                $recommendation->save();

                $stored[] = $recommendation;
            }

            return $stored;
        });
    }

    /**
     * The insights themselves, without storing anything.
     *
     * @return list<array{headline: string, body: string, payload: array<string, mixed>}>
     */
    public function compute(Organization $organization, Carbon $from, Carbon $to): array
    {
        $campaigns = $this->analytics->campaignBreakdown($organization, $from, $to, limit: 100);

        if ($campaigns === []) {
            return [];
        }

        return array_values(array_filter([
            $this->costPerConversionGap($campaigns),
            $this->spendWithoutConversions($campaigns),
        ]));
    }

    /**
     * §47's own example: two campaigns, very different costs per result.
     *
     * @param  list<array<string, mixed>>  $campaigns
     * @return array{headline: string, body: string, payload: array<string, mixed>}|null
     */
    private function costPerConversionGap(array $campaigns): ?array
    {
        $comparable = array_values(array_filter(
            $campaigns,
            static fn (array $row): bool => (int) ($row['conversions'] ?? 0) >= self::MINIMUM_CONVERSIONS
                && ($row['costPerConversion'] ?? null) !== null,
        ));

        if (count($comparable) < 2) {
            return null;
        }

        /*
         * Compared within one currency only. A campaign billed in dollars and
         * one in taka have costs per conversion that cannot be ranked without
         * a rate, and a rate this service invented would be a recommendation
         * built on a number nobody chose (Rule 8, §35).
         */
        $byCurrency = [];

        foreach ($comparable as $row) {
            $byCurrency[(string) ($row['currency'] ?? '')][] = $row;
        }

        foreach ($byCurrency as $rows) {
            if (count($rows) < 2) {
                continue;
            }

            usort($rows, static fn (array $a, array $b): int => $a['costPerConversionMinor'] <=> $b['costPerConversionMinor']);

            $best = $rows[0];
            $worst = $rows[count($rows) - 1];

            if ($best['costPerConversionMinor'] <= 0) {
                continue;
            }

            if ($worst['costPerConversionMinor'] < $best['costPerConversionMinor'] * self::MATERIAL_MULTIPLE) {
                continue;
            }

            return [
                'headline' => 'One campaign is costing far more per result than another',
                'body' => sprintf(
                    '%s is costing %s per conversion and %s is costing %s. '
                    .'Moving budget towards %s would buy more results for the same spend, '
                    .'if the two are reaching audiences you value equally.',
                    $worst['name'],
                    $worst['costPerConversion'],
                    $best['name'],
                    $best['costPerConversion'],
                    $best['name'],
                ),
                'payload' => [
                    'better' => ['campaign' => $best['id'], 'cost_per_conversion' => $best['costPerConversion']],
                    'worse' => ['campaign' => $worst['id'], 'cost_per_conversion' => $worst['costPerConversion']],
                    'currency' => $best['currency'] ?? null,
                ],
            ];
        }

        return null;
    }

    /**
     * Money spent on a campaign that has produced nothing measurable.
     *
     * Carefully worded: conversions the platform cannot see are still
     * conversions. This says the tracking shows none, not that none happened —
     * the commonest cause is conversion tracking that was never finished, and
     * telling a client their campaign failed when their pixel is missing would
     * be wrong and expensive.
     *
     * @param  list<array<string, mixed>>  $campaigns
     * @return array{headline: string, body: string, payload: array<string, mixed>}|null
     */
    private function spendWithoutConversions(array $campaigns): ?array
    {
        $silent = array_values(array_filter(
            $campaigns,
            static fn (array $row): bool => (int) ($row['conversions'] ?? 0) === 0
                && (int) ($row['spendMinor'] ?? 0) > 0
                && (int) ($row['clicks'] ?? 0) >= 100,
        ));

        if ($silent === []) {
            return null;
        }

        usort($silent, static fn (array $a, array $b): int => $b['spendMinor'] <=> $a['spendMinor']);

        $worst = $silent[0];

        $spend = Money::ofMinor((int) $worst['spendMinor'], (string) $worst['currency']);

        return [
            'headline' => 'A campaign is spending without any conversions being recorded',
            'body' => sprintf(
                '%s has spent %s and had %s clicks, but no conversions have been reported. '
                .'That is often conversion tracking that has not been set up rather than a campaign '
                .'that is not working — worth checking before changing anything.',
                $worst['name'],
                $spend->format(),
                number_format((int) $worst['clicks']),
            ),
            'payload' => [
                'campaign' => $worst['id'],
                'spend' => $spend->format(),
                'clicks' => (int) $worst['clicks'],
            ],
        ];
    }
}
