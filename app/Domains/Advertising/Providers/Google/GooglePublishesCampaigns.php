<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Providers\Google;

use App\Domains\Advertising\DTOs\AdDraft;
use App\Domains\Advertising\DTOs\AdSetDraft;
use App\Domains\Advertising\DTOs\CampaignDraft;
use App\Domains\Advertising\DTOs\CampaignInsights;
use App\Domains\Advertising\DTOs\DailyInsightRow;
use App\Domains\Advertising\DTOs\PublishedEntity;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Campaign\Enums\BidStrategy;
use App\Domains\Campaign\Enums\BudgetType;
use App\Domains\Campaign\Enums\CampaignObjective;
use App\Domains\Campaign\Values\Targeting;
use App\Support\Values\Currency;
use DateTimeImmutable;

/**
 * Creating and controlling campaigns at Google Ads (spec §21, §26, Rule 17).
 *
 * ## How idempotency works here
 *
 * Google has no idempotency-key header any more than Meta does. What it has
 * instead is better: it *enforces name uniqueness itself*. A campaign name is
 * unique within a customer account, a budget name is unique within a customer
 * account, and an ad group name is unique within a campaign. A repeated
 * creation is therefore refused by Google rather than silently duplicated.
 *
 * The adapter turns that into a guarantee in three steps:
 *
 *   1. Every object it creates carries the platform's own reference in its
 *      name, as a `[ads360:<reference>]` suffix — the same convention as the
 *      Meta adapter, so an operator reading either provider's interface sees
 *      the same marker.
 *   2. Before creating anything it looks the reference up with a GAQL query.
 *      A match means a previous attempt already succeeded.
 *   3. If the creation still races another worker, Google refuses it with a
 *      duplicate-name error, and the adapter treats that refusal as proof the
 *      first attempt landed and goes back to the lookup.
 *
 * Step 3 is what Meta cannot offer. There, a lost race creates a second
 * campaign with a second budget; here, Google will not let it happen.
 *
 * ## What this adapter publishes, and what it does not
 *
 * Search campaigns. Not Display, Video, Shopping or Performance Max: each
 * needs different ad formats, different creative requirements and different
 * targeting shapes, and declaring them before they are built would have
 * callers offer clients something that does not work (§87). That is why
 * `CampaignObjective::for(Provider::Google)` lists the three objectives a
 * search campaign genuinely serves.
 *
 * The same honesty applies to targeting. A Google search campaign cannot
 * express interest, gender or device targeting as *targeting* — Google offers
 * those only as bid adjustments there. Rather than accept the client's
 * narrowing and quietly not apply it, which would spend their budget on an
 * audience they did not choose, the adapter refuses and says which dimension
 * it cannot honour.
 *
 * ## Nothing here works around a refusal
 *
 * A policy decision, an account suspension, a billing hold or an eligibility
 * finding is reported as it stands (§27).
 */
trait GooglePublishesCampaigns
{
    /** How the platform's reference is embedded in a Google object's name. */
    private const REFERENCE_PREFIX = 'ads360:';

    /** Google's limit on a campaign, budget or ad group name. */
    private const MAX_NAME_LENGTH = 255;

    // ------------------------------------------------------------------
    // Campaigns
    // ------------------------------------------------------------------

    public function createCampaign(
        AdAccount $account,
        CampaignDraft $draft,
        string $idempotencyKey,
    ): PublishedEntity {
        $customerId = $this->customerId($account);
        $this->assertSameCurrency($account->currency, $draft->currency);

        $existing = $this->existingCampaign($customerId, $draft->reference);

        if ($existing !== null) {
            return $existing;
        }

        /*
         * The budget first, and as its own resource. Google models a budget as
         * an object a campaign points at rather than as a field on the
         * campaign, so publishing a campaign is two creations — and the budget
         * survives the campaign, which is why it carries the same reference
         * and is looked up the same way.
         */
        $budget = $this->resolveBudget($customerId, $draft);

        $payload = array_filter([
            'name' => $this->nameFor($draft->name, $draft->reference),
            'campaignBudget' => $budget,

            /*
             * Created paused, always — the same rule as the Meta adapter and
             * for the same reason. A campaign that went live the instant it
             * was created would start spending before its ad groups and ads
             * existed, on a structure that is not yet what was approved.
             */
            'status' => 'PAUSED',
            'advertisingChannelType' => 'SEARCH',
            'networkSettings' => [
                'targetGoogleSearch' => true,
                'targetSearchNetwork' => true,
                /*
                 * Off deliberately. The Display network on a search campaign
                 * spends part of a search budget on placements the client
                 * never chose, and Google turns it on by default.
                 */
                'targetContentNetwork' => false,
                'targetPartnerSearchNetwork' => false,
            ],
            'startDate' => $draft->startsAt?->format('Y-m-d'),
            'endDate' => $draft->endsAt?->format('Y-m-d'),
        ], static fn (mixed $value): bool => $value !== null);

        $payload += $this->biddingFor($draft->objective);

        try {
            $resourceName = $this->platformClient()->mutateOne($customerId, 'campaigns', ['create' => $payload]);
        } catch (DuplicateResourceName $exception) {
            // Google refused a second campaign with this name, which means the
            // first one exists. Exactly the guarantee the reference is for.
            return $this->existingCampaign($customerId, $draft->reference)
                ?? throw $exception;
        }

        return new PublishedEntity(
            externalId: $resourceName,
            status: 'PAUSED',
            wasExisting: false,
            raw: ['campaign_budget' => $budget],
        );
    }

    // ------------------------------------------------------------------
    // Ad groups
    // ------------------------------------------------------------------

    /**
     * An ad set, which Google calls an ad group.
     *
     * Three things the platform models per ad set live on the *campaign* at
     * Google: the bidding strategy, the geography and the schedule. Each is
     * applied to the parent campaign here, and each refuses rather than
     * overwrites when a second ad set asks for something different — silently
     * flipping a campaign's bidding because a second audience was added would
     * change how the client's first audience spends.
     */
    public function createAdSet(
        AdAccount $account,
        AdSetDraft $draft,
        string $idempotencyKey,
    ): PublishedEntity {
        $customerId = $this->customerId($account);
        $campaign = $this->campaignResourceName($customerId, $draft->externalCampaignId);

        /*
         * The resume check comes first, before anything is applied to the
         * campaign. A retry that re-ran the campaign-level steps would find
         * this ad set's own siblings already in place and refuse its own
         * earlier work as a conflict.
         */
        $existing = $this->existingAdGroup($customerId, $campaign, $draft->reference);

        if ($existing !== null) {
            $this->applyAudiences($customerId, $existing->externalId, $draft->targeting);

            return $existing;
        }

        $this->assertTargetingIsExpressible($draft->targeting);

        $campaignFields = $this->campaignFields($customerId, $campaign);

        $this->applySchedule($customerId, $campaign, $campaignFields, $draft);
        $this->applyBidStrategy($customerId, $campaign, $campaignFields, $draft);
        $this->applyGeography($customerId, $campaign, $draft->targeting);
        $this->applyLanguages($customerId, $campaign, $draft->targeting);

        $payload = array_filter([
            'name' => $this->nameFor($draft->name, $draft->reference),
            'campaign' => $campaign,
            'status' => 'PAUSED',
            'type' => 'SEARCH_STANDARD',

            /*
             * Only meaningful under a manual bidding strategy. Under an
             * automated one Google ignores it, which is why it is sent rather
             * than made conditional: it is the ceiling the client asked for,
             * and it costs nothing to record where Google will honour it.
             */
            'cpcBidMicros' => $draft->bidAmountMinor === null
                ? null
                : (string) Micros::fromMinor($draft->bidAmountMinor, $account->currency),

            /*
             * Without this, an audience attached to a search ad group is
             * "observation" — Google reports on it and targets everyone
             * anyway. `bidOnly: false` makes it a restriction, which is what
             * a client selecting an audience meant.
             */
            'targetingSetting' => $draft->targeting->customAudiences === [] ? null : [
                'targetRestrictions' => [[
                    'targetingDimension' => 'AUDIENCE',
                    'bidOnly' => false,
                ]],
            ],
        ], static fn (mixed $value): bool => $value !== null);

        try {
            $resourceName = $this->platformClient()->mutateOne($customerId, 'adGroups', ['create' => $payload]);
        } catch (DuplicateResourceName $exception) {
            $recovered = $this->existingAdGroup($customerId, $campaign, $draft->reference);

            if ($recovered === null) {
                throw $exception;
            }

            $this->applyAudiences($customerId, $recovered->externalId, $draft->targeting);

            return $recovered;
        }

        $this->applyAudiences($customerId, $resourceName, $draft->targeting);

        return new PublishedEntity(
            externalId: $resourceName,
            status: 'PAUSED',
            wasExisting: false,
            raw: [
                // The budget on an ad set has nowhere to go: Google budgets a
                // campaign, not an ad group. Recorded so a reviewer reading
                // the publication ledger can see it was not applied rather
                // than assuming it was.
                'ad_set_budget_not_applied' => $draft->budgetMinor !== null,
            ],
        );
    }

    // ------------------------------------------------------------------
    // Ads
    // ------------------------------------------------------------------

    /**
     * A responsive search ad.
     *
     * Google requires at least three headlines and two descriptions, and
     * assembles them into combinations at auction time. The adapter will not
     * make up the missing ones: an ad is the client's own words, and inventing
     * copy to satisfy a minimum would put sentences in their mouth that they
     * never approved and cannot see before it runs. A draft that is short is
     * refused with a message saying exactly what Google needs.
     */
    public function createAd(
        AdAccount $account,
        AdDraft $draft,
        string $idempotencyKey,
    ): PublishedEntity {
        $customerId = $this->customerId($account);
        $adGroup = $this->adGroupResourceName($customerId, $draft->externalAdSetId);

        $existing = $this->existingAd($customerId, $adGroup, $draft->reference);

        if ($existing !== null) {
            return $existing;
        }

        $headlines = $this->headlinesFor($draft);
        $descriptions = $this->descriptionsFor($draft);

        $payload = [
            'adGroup' => $adGroup,
            'status' => 'PAUSED',
            'ad' => [
                // Google's `ad.name` is a label, not copy: it never serves.
                // It is where the platform's reference lives.
                'name' => $this->nameFor($draft->name, $draft->reference),
                'finalUrls' => [$draft->destinationUrl],
                'responsiveSearchAd' => array_filter([
                    'headlines' => array_map(
                        static fn (string $text): array => ['text' => $text],
                        $headlines,
                    ),
                    'descriptions' => array_map(
                        static fn (string $text): array => ['text' => $text],
                        $descriptions,
                    ),
                ], static fn (mixed $value): bool => $value !== null),
            ],
        ];

        try {
            $resourceName = $this->platformClient()->mutateOne($customerId, 'adGroupAds', ['create' => $payload]);
        } catch (DuplicateResourceName $exception) {
            return $this->existingAd($customerId, $adGroup, $draft->reference) ?? throw $exception;
        }

        return new PublishedEntity(externalId: $resourceName, status: 'PAUSED', wasExisting: false);
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    public function setCampaignActive(
        AdAccount $account,
        string $externalCampaignId,
        bool $active,
        string $idempotencyKey,
    ): void {
        $customerId = $this->customerId($account);

        // Repeats are harmless: Google accepts a status it is already in.
        $this->platformClient()->mutate($customerId, 'campaigns', [[
            'update' => [
                'resourceName' => $this->campaignResourceName($customerId, $externalCampaignId),
                'status' => $active ? 'ENABLED' : 'PAUSED',
            ],
            'updateMask' => 'status',
        ]]);
    }

    /**
     * Stop a campaign for good.
     *
     * Google's `remove` is not a deletion in the sense that matters here: the
     * campaign stays queryable with status REMOVED and its spend keeps
     * reporting, which is what lets it go on reconciling against the client's
     * ledger afterwards (§62). It is irreversible, which is why this is a
     * separate operation from pausing and not a convenience on top of it.
     */
    public function stopCampaign(
        AdAccount $account,
        string $externalCampaignId,
        string $idempotencyKey,
    ): void {
        $customerId = $this->customerId($account);

        $this->platformClient()->mutate($customerId, 'campaigns', [[
            'remove' => $this->campaignResourceName($customerId, $externalCampaignId),
        ]]);
    }

    // ------------------------------------------------------------------
    // Insights
    // ------------------------------------------------------------------

    public function campaignInsights(AdAccount $account, string $externalCampaignId): CampaignInsights
    {
        $customerId = $this->customerId($account);
        $campaignId = $this->campaignId($externalCampaignId);

        /*
         * No date condition, which in GAQL means every day the campaign has
         * ever run. That is what the ledger reconciles against: spend to date,
         * not spend in a window (§78).
         */
        $row = $this->platformClient()->searchOne(
            $customerId,
            'SELECT campaign.id, campaign.status, metrics.cost_micros, metrics.impressions, '
            .'metrics.clicks, metrics.conversions '
            ."FROM campaign WHERE campaign.id = {$campaignId}",
        );

        if ($row === null) {
            /*
             * Google returns no row for a campaign that has never served. That
             * is "nothing reported", not zero — a campaign published minutes
             * ago must not have its wallet hold released as though it had
             * finished (§87).
             */
            return new CampaignInsights(
                externalCampaignId: $externalCampaignId,
                raw: ['insights' => 'empty'],
            );
        }

        $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
        $campaign = is_array($row['campaign'] ?? null) ? $row['campaign'] : [];

        return new CampaignInsights(
            externalCampaignId: $externalCampaignId,
            spendMinor: Micros::toMinor($metrics['costMicros'] ?? null, $account->currency),
            currency: $account->currency,
            impressions: isset($metrics['impressions']) ? (int) $metrics['impressions'] : null,
            clicks: isset($metrics['clicks']) ? (int) $metrics['clicks'] : null,
            conversions: $this->conversionsFrom($metrics),
            status: isset($campaign['status']) ? (string) $campaign['status'] : null,
            raw: ['cost_micros' => $metrics['costMicros'] ?? null],
        );
    }

    /**
     * Day-by-day performance over a window (spec §38, §78).
     *
     * `segments.date` in the SELECT is what makes Google return one row per
     * day rather than one row for the range — GAQL's equivalent of Meta's
     * `time_increment`. The window is re-fetched rather than only extended,
     * because Google restates: a conversion counted days after the click is
     * attributed to the day of the click, changing a figure already reported.
     *
     * @return list<DailyInsightRow>
     */
    public function campaignDailyInsights(
        AdAccount $account,
        string $externalCampaignId,
        DateTimeImmutable $since,
        DateTimeImmutable $until,
    ): array {
        $customerId = $this->customerId($account);
        $campaignId = $this->campaignId($externalCampaignId);

        $rows = $this->platformClient()->search(
            $customerId,
            'SELECT segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, '
            .'metrics.conversions, metrics.conversions_value '
            .'FROM campaign '
            ."WHERE campaign.id = {$campaignId} "
            ."AND segments.date BETWEEN '{$since->format('Y-m-d')}' AND '{$until->format('Y-m-d')}'",
            pageSize: 500,
        );

        $insights = [];

        foreach ($rows as $row) {
            $date = $row['segments']['date'] ?? null;

            if (! is_string($date)) {
                continue;
            }

            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed === false) {
                continue;
            }

            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];

            $insights[] = new DailyInsightRow(
                date: $parsed,
                spendMinor: Micros::toMinor($metrics['costMicros'] ?? null, $account->currency),
                currency: $account->currency,
                impressions: isset($metrics['impressions']) ? (int) $metrics['impressions'] : null,
                clicks: isset($metrics['clicks']) ? (int) $metrics['clicks'] : null,
                /*
                 * Google publishes no reach figure for search campaigns.
                 * Null is the honest answer; a zero would be drawn on a
                 * client's chart as a day nobody saw their ads (§87).
                 */
                reach: null,
                conversions: $this->conversionsFrom($metrics),
                conversionValueMinor: $this->currencyToMinor(
                    $metrics['conversionsValue'] ?? null,
                    $account->currency,
                ),
                raw: ['date' => $date],
            );
        }

        return $insights;
    }

    // ------------------------------------------------------------------
    // Idempotency: finding what a previous attempt created
    // ------------------------------------------------------------------

    /**
     * A campaign this platform already created for a reference.
     *
     * The marker is verified in PHP as well as in the query. A `LIKE` is a
     * pattern match, and this one decides whether to create a second campaign
     * with a second budget — the cost of being wrong is a client's money spent
     * twice, so it is worth confirming rather than trusting.
     *
     * @throws ProviderUnavailable
     */
    private function existingCampaign(string $customerId, string $reference): ?PublishedEntity
    {
        $marker = $this->marker($reference);

        $rows = $this->platformClient()->search(
            $customerId,
            'SELECT campaign.id, campaign.resource_name, campaign.name, campaign.status '
            ."FROM campaign WHERE campaign.name LIKE '%".GoogleAdsClient::escape($marker)."%' "
            // A removed campaign frees its name at Google, so one that matches
            // is not proof of a previous success — it is a campaign that was
            // stopped, and republishing means creating a new one.
            ."AND campaign.status != 'REMOVED'",
            pageSize: 50,
            maxPages: 1,
        );

        foreach ($rows as $row) {
            $campaign = is_array($row['campaign'] ?? null) ? $row['campaign'] : [];

            if (! $this->carriesMarker($campaign['name'] ?? null, $marker)) {
                continue;
            }

            return new PublishedEntity(
                externalId: (string) ($campaign['resourceName'] ?? ''),
                status: isset($campaign['status']) ? (string) $campaign['status'] : null,
                // The signal that stops the publication ledger recording a
                // second creation as though it were the first.
                wasExisting: true,
                raw: ['matched_on' => 'name_reference'],
            );
        }

        return null;
    }

    /**
     * @throws ProviderUnavailable
     */
    private function existingAdGroup(string $customerId, string $campaign, string $reference): ?PublishedEntity
    {
        $marker = $this->marker($reference);

        $rows = $this->platformClient()->search(
            $customerId,
            'SELECT ad_group.id, ad_group.resource_name, ad_group.name, ad_group.status '
            .'FROM ad_group '
            .'WHERE campaign.id = '.$this->campaignId($campaign).' '
            ."AND ad_group.name LIKE '%".GoogleAdsClient::escape($marker)."%' "
            ."AND ad_group.status != 'REMOVED'",
            pageSize: 50,
            maxPages: 1,
        );

        foreach ($rows as $row) {
            $adGroup = is_array($row['adGroup'] ?? null) ? $row['adGroup'] : [];

            if (! $this->carriesMarker($adGroup['name'] ?? null, $marker)) {
                continue;
            }

            return new PublishedEntity(
                externalId: (string) ($adGroup['resourceName'] ?? ''),
                status: isset($adGroup['status']) ? (string) $adGroup['status'] : null,
                wasExisting: true,
                raw: ['matched_on' => 'name_reference'],
            );
        }

        return null;
    }

    /**
     * An ad this platform already created.
     *
     * Matched in PHP rather than with a `LIKE`: `ad_group_ad.ad.name` is a
     * nested field on a nested resource, and an ad group holds few enough ads
     * that fetching them and comparing is both simpler and certain.
     *
     * @throws ProviderUnavailable
     */
    private function existingAd(string $customerId, string $adGroup, string $reference): ?PublishedEntity
    {
        $marker = $this->marker($reference);

        $rows = $this->platformClient()->search(
            $customerId,
            'SELECT ad_group_ad.resource_name, ad_group_ad.status, ad_group_ad.ad.id, ad_group_ad.ad.name '
            .'FROM ad_group_ad '
            .'WHERE ad_group.id = '.$this->resourceId($adGroup).' '
            ."AND ad_group_ad.status != 'REMOVED'",
            pageSize: 200,
            maxPages: 2,
        );

        foreach ($rows as $row) {
            $adGroupAd = is_array($row['adGroupAd'] ?? null) ? $row['adGroupAd'] : [];
            $ad = is_array($adGroupAd['ad'] ?? null) ? $adGroupAd['ad'] : [];

            if (! $this->carriesMarker($ad['name'] ?? null, $marker)) {
                continue;
            }

            return new PublishedEntity(
                externalId: (string) ($adGroupAd['resourceName'] ?? ''),
                status: isset($adGroupAd['status']) ? (string) $adGroupAd['status'] : null,
                wasExisting: true,
                raw: ['matched_on' => 'name_reference'],
            );
        }

        return null;
    }

    /**
     * The budget a campaign will point at, created if it is not there yet.
     *
     * Google budgets are named and unique per customer, so the same reference
     * that makes a campaign idempotent makes its budget idempotent too. A
     * campaign whose creation failed after its budget was made resumes onto
     * the same budget rather than leaving an orphan behind.
     *
     * @throws ProviderUnavailable
     */
    private function resolveBudget(string $customerId, CampaignDraft $draft): string
    {
        $marker = $this->marker($draft->reference);
        $existing = $this->findBudget($customerId, $marker);

        if ($existing !== null) {
            return $existing;
        }

        $operation = ['create' => [
            'name' => $this->nameFor($draft->name.' budget', $draft->reference),
            'amountMicros' => (string) $this->dailyBudgetMicros($draft),
            'deliveryMethod' => 'STANDARD',
            // Not shared. A shared budget would let another campaign on the
            // same account spend against this client's reservation.
            'explicitlyShared' => false,
        ]];

        try {
            return $this->platformClient()->mutateOne($customerId, 'campaignBudgets', $operation);
        } catch (DuplicateResourceName $exception) {
            return $this->findBudget($customerId, $marker) ?? throw $exception;
        }
    }

    /**
     * @throws ProviderUnavailable
     */
    private function findBudget(string $customerId, string $marker): ?string
    {
        $rows = $this->platformClient()->search(
            $customerId,
            'SELECT campaign_budget.id, campaign_budget.resource_name, campaign_budget.name '
            ."FROM campaign_budget WHERE campaign_budget.name LIKE '%".GoogleAdsClient::escape($marker)."%' "
            ."AND campaign_budget.status != 'REMOVED'",
            pageSize: 50,
            maxPages: 1,
        );

        foreach ($rows as $row) {
            $budget = is_array($row['campaignBudget'] ?? null) ? $row['campaignBudget'] : [];

            if ($this->carriesMarker($budget['name'] ?? null, $marker)) {
                return (string) ($budget['resourceName'] ?? '');
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Budget and bidding
    // ------------------------------------------------------------------

    /**
     * The daily budget Google will pace against, in micros.
     *
     * Google has one kind of campaign budget and it is a daily average. The
     * platform offers two, and a lifetime budget therefore has to be spread
     * across the run.
     *
     * Two things about that spreading matter:
     *
     *   - It **floors**. `intdiv` rather than rounding, so the derived daily
     *     figure times the number of days can never exceed what the client
     *     reserved. A rounded-up figure would let Google pace above the
     *     wallet hold that authorised the campaign (Rule 8).
     *   - It needs an end date. A total budget with no end has no number of
     *     days to divide by, and inventing one — a month, a year — would be
     *     the platform choosing how fast a client's money is spent. So it is
     *     refused, with a message saying what to add.
     *
     * Google may still spend up to twice a daily budget on a single busy day,
     * compensating on quieter ones. That is Google's pacing and cannot be
     * turned off; what bounds the client's exposure is the campaign's end date
     * and the ledger, which captures actual spend rather than the plan (§78).
     *
     * @throws ProviderUnavailable
     */
    private function dailyBudgetMicros(CampaignDraft $draft): int
    {
        if ($draft->budgetType === BudgetType::Daily) {
            return Micros::fromMinor($draft->budgetMinor, $draft->currency);
        }

        if ($draft->endsAt === null) {
            throw ProviderUnavailable::refused(
                Provider::Google,
                'a lifetime budget was given with no end date, which Google cannot pace',
                'Google Ads spreads a total budget across a fixed run. Please give this campaign an end date.',
            );
        }

        $start = $draft->startsAt ?? new DateTimeImmutable;
        $days = (int) $start->setTime(0, 0)->diff($draft->endsAt->setTime(0, 0))->days;

        // Inclusive: a campaign that starts and ends on the same day runs for
        // one day, not zero.
        $days = max(1, $days + 1);

        return intdiv(Micros::fromMinor($draft->budgetMinor, $draft->currency), $days);
    }

    /**
     * The campaign-level bidding strategy an objective implies.
     *
     * Google has no "objective" field. What the platform calls an objective is
     * expressed there as a choice of bidding strategy, so the translation is
     * from one vocabulary into another rather than into a single value.
     *
     * @return array<string, mixed>
     */
    private function biddingFor(CampaignObjective $objective): array
    {
        return match ($objective) {
            // Maximise clicks: as many visits as the budget buys.
            CampaignObjective::Traffic => ['targetSpend' => new \stdClass],

            // Maximise conversions: as many enquiries as the budget buys.
            CampaignObjective::Leads => ['maximizeConversions' => new \stdClass],

            // Maximise conversion value: worth, not count.
            CampaignObjective::Sales => ['maximizeConversionValue' => new \stdClass],

            /*
             * Unreachable through the platform: CampaignObjective::for(Google)
             * lists only the three above, because a search campaign is what
             * this adapter publishes. Left explicit rather than defaulted, so
             * adding an objective to that list without adding it here is a
             * failure at publish time rather than a campaign quietly bidding
             * for something nobody chose.
             */
            default => throw ProviderUnavailable::notSupported(
                Provider::Google,
                'the objective '.$objective->value.' on a search campaign',
            ),
        };
    }

    /**
     * Apply the ad set's bidding to its campaign, where Google keeps it.
     *
     * The platform models a bid strategy per ad set; Google models it per
     * campaign. So the first ad set's strategy becomes the campaign's, and a
     * second ad set asking for a different one is refused rather than allowed
     * to change how the first one spends.
     *
     * @param  array<string, mixed>  $campaignFields
     *
     * @throws ProviderUnavailable
     */
    private function applyBidStrategy(
        string $customerId,
        string $campaign,
        array $campaignFields,
        AdSetDraft $draft,
    ): void {
        $desired = $this->biddingOverrideFor($draft->bidStrategy);

        if ($desired === null) {
            // Lowest cost is "whatever the objective chose, uncapped", which
            // is exactly what the campaign already carries.
            return;
        }

        $current = (string) ($campaignFields['biddingStrategyType'] ?? '');

        if ($current === $desired['type']) {
            return;
        }

        if ($this->campaignHasAdGroups($customerId, $campaign)) {
            throw ProviderUnavailable::refused(
                Provider::Google,
                "the campaign already bids as {$current} and this audience asks for {$desired['type']}",
                'Google Ads sets the bidding strategy for a whole campaign, not per audience. '
                .'Every audience in this campaign has to use the same one.',
            );
        }

        $payload = ['resourceName' => $campaign] + $desired['fields'];

        if ($draft->bidAmountMinor !== null && isset($desired['amountField'])) {
            $payload[$desired['field']][$desired['amountField']] =
                (string) Micros::fromMinor($draft->bidAmountMinor, $this->currencyOf($customerId));
        }

        $this->platformClient()->mutate($customerId, 'campaigns', [[
            'update' => $payload,
            'updateMask' => $desired['field'],
        ]]);
    }

    /**
     * How a platform bid strategy is expressed at Google, or null when it
     * asks for nothing the objective did not already set.
     *
     * @return array{type: string, field: string, fields: array<string, mixed>, amountField?: string}|null
     */
    private function biddingOverrideFor(BidStrategy $strategy): ?array
    {
        return match ($strategy) {
            BidStrategy::LowestCost => null,

            /*
             * A bid cap is a manual ceiling, which at Google means manual CPC
             * with the ceiling on the ad group. Enhanced CPC is off: it lets
             * Google bid above the cap the client set.
             */
            BidStrategy::BidCap => [
                'type' => 'MANUAL_CPC',
                'field' => 'manualCpc',
                'fields' => ['manualCpc' => ['enhancedCpcEnabled' => false]],
            ],

            // Both cost cap and target cost are "keep the average near this",
            // which Google expresses as a target CPA on maximise-conversions.
            BidStrategy::CostCap, BidStrategy::TargetCost => [
                'type' => 'MAXIMIZE_CONVERSIONS',
                'field' => 'maximizeConversions',
                'fields' => ['maximizeConversions' => []],
                'amountField' => 'targetCpaMicros',
            ],
        };
    }

    // ------------------------------------------------------------------
    // Campaign-level settings the platform models per ad set
    // ------------------------------------------------------------------

    /**
     * Apply the ad set's schedule to its campaign.
     *
     * Google schedules a campaign, not an ad group. Where the campaign already
     * carries dates and the ad set asks for different ones, the difference is
     * refused: running an audience for longer than the client asked spends
     * money they did not authorise, and running it for less quietly
     * under-delivers what they paid for.
     *
     * @param  array<string, mixed>  $campaignFields
     *
     * @throws ProviderUnavailable
     */
    private function applySchedule(
        string $customerId,
        string $campaign,
        array $campaignFields,
        AdSetDraft $draft,
    ): void {
        $updates = [];

        foreach ([['startsAt', 'startDate'], ['endsAt', 'endDate']] as [$property, $field]) {
            $wanted = $draft->{$property}?->format('Y-m-d');

            if ($wanted === null) {
                continue;
            }

            $current = $campaignFields[$field] ?? null;

            if (is_string($current) && $current !== '' && $current !== $wanted) {
                throw ProviderUnavailable::refused(
                    Provider::Google,
                    "the campaign runs {$field}={$current} and this audience asks for {$wanted}",
                    'Google Ads schedules a whole campaign, not each audience separately. '
                    .'Every audience in this campaign has to run over the same dates.',
                );
            }

            if (! is_string($current) || $current === '') {
                $updates[$field] = $wanted;
            }
        }

        if ($updates === []) {
            return;
        }

        $this->platformClient()->mutate($customerId, 'campaigns', [[
            'update' => ['resourceName' => $campaign] + $updates,
            'updateMask' => implode(',', array_keys($updates)),
        ]]);
    }

    /**
     * Attach the campaign's geography.
     *
     * Google targets locations by constant — an opaque numeric identifier for
     * a country, region or city — not by ISO code, so a country has to be
     * looked up before it can be targeted. A country the lookup cannot resolve
     * is refused rather than dropped: publishing without a narrowing the client
     * asked for would spend their budget somewhere they did not choose.
     *
     * @throws ProviderUnavailable
     */
    private function applyGeography(string $customerId, string $campaign, Targeting $targeting): void
    {
        $wanted = $this->geoConstantsFor($customerId, $targeting);

        if ($wanted === []) {
            return;
        }

        $existing = $this->existingCriteria(
            $customerId,
            $campaign,
            'LOCATION',
            'campaign_criterion.location.geo_target_constant',
            static fn (array $criterion): ?string => is_array($criterion['location'] ?? null)
                ? (string) ($criterion['location']['geoTargetConstant'] ?? '')
                : null,
        );

        if ($existing !== [] && array_diff($wanted, $existing) !== []) {
            throw ProviderUnavailable::refused(
                Provider::Google,
                'the campaign already targets '.implode(',', $existing)
                .' and this audience asks for '.implode(',', $wanted),
                'Google Ads sets the locations for a whole campaign, not each audience separately. '
                .'Every audience in this campaign has to target the same places.',
            );
        }

        $missing = array_values(array_diff($wanted, $existing));

        if ($missing === []) {
            return;
        }

        $this->platformClient()->mutate($customerId, 'campaignCriteria', array_map(
            static fn (string $constant): array => ['create' => [
                'campaign' => $campaign,
                'location' => ['geoTargetConstant' => $constant],
            ]],
            $missing,
        ));
    }

    /**
     * Attach the campaign's languages, if the client named any.
     *
     * @throws ProviderUnavailable
     */
    private function applyLanguages(string $customerId, string $campaign, Targeting $targeting): void
    {
        if ($targeting->languages === []) {
            return;
        }

        $wanted = $this->languageConstantsFor($customerId, $targeting->languages);

        $existing = $this->existingCriteria(
            $customerId,
            $campaign,
            'LANGUAGE',
            'campaign_criterion.language.language_constant',
            static fn (array $criterion): ?string => is_array($criterion['language'] ?? null)
                ? (string) ($criterion['language']['languageConstant'] ?? '')
                : null,
        );

        $missing = array_values(array_diff($wanted, $existing));

        if ($missing === []) {
            return;
        }

        $this->platformClient()->mutate($customerId, 'campaignCriteria', array_map(
            static fn (string $constant): array => ['create' => [
                'campaign' => $campaign,
                'language' => ['languageConstant' => $constant],
            ]],
            $missing,
        ));
    }

    /**
     * Attach the client's own audiences to the ad group.
     *
     * Only lists the client already owns at Google. The platform never builds
     * an audience out of a client's data and never shares one between clients.
     *
     * @throws ProviderUnavailable
     */
    private function applyAudiences(string $customerId, string $adGroup, Targeting $targeting): void
    {
        if ($targeting->customAudiences === []) {
            return;
        }

        $wanted = array_values(array_map(
            static fn (string $id): string => str_contains($id, '/') ? $id : "customers/{$customerId}/userLists/{$id}",
            $targeting->customAudiences,
        ));

        $rows = $this->platformClient()->search(
            $customerId,
            'SELECT ad_group_criterion.criterion_id, ad_group_criterion.user_list.user_list '
            .'FROM ad_group_criterion '
            .'WHERE ad_group.id = '.$this->resourceId($adGroup).' '
            ."AND ad_group_criterion.type = 'USER_LIST' "
            ."AND ad_group_criterion.status != 'REMOVED'",
            pageSize: 200,
            maxPages: 1,
        );

        $existing = [];

        foreach ($rows as $row) {
            $criterion = is_array($row['adGroupCriterion'] ?? null) ? $row['adGroupCriterion'] : [];
            $list = is_array($criterion['userList'] ?? null) ? ($criterion['userList']['userList'] ?? null) : null;

            if (is_string($list) && $list !== '') {
                $existing[] = $list;
            }
        }

        $missing = array_values(array_diff($wanted, $existing));

        if ($missing === []) {
            return;
        }

        $this->platformClient()->mutate($customerId, 'adGroupCriteria', array_map(
            static fn (string $list): array => ['create' => [
                'adGroup' => $adGroup,
                'userList' => ['userList' => $list],
            ]],
            $missing,
        ));
    }

    /**
     * Criteria already on a campaign, so re-running publication adds nothing
     * twice — Google refuses a duplicate criterion outright.
     *
     * @param  callable(array<string, mixed>): ?string  $extract
     * @return list<string>
     *
     * @throws ProviderUnavailable
     */
    private function existingCriteria(
        string $customerId,
        string $campaign,
        string $type,
        string $field,
        callable $extract,
    ): array {
        $rows = $this->platformClient()->search(
            $customerId,
            "SELECT campaign_criterion.criterion_id, {$field} "
            .'FROM campaign_criterion '
            .'WHERE campaign.id = '.$this->campaignId($campaign).' '
            ."AND campaign_criterion.type = '{$type}' "
            .'AND campaign_criterion.negative = false',
            pageSize: 200,
            maxPages: 2,
        );

        $found = [];

        foreach ($rows as $row) {
            $criterion = is_array($row['campaignCriterion'] ?? null) ? $row['campaignCriterion'] : [];
            $value = $extract($criterion);

            if (is_string($value) && $value !== '') {
                $found[] = $value;
            }
        }

        return $found;
    }

    /**
     * Country codes and provider location identifiers as Google constants.
     *
     * @return list<string>
     *
     * @throws ProviderUnavailable
     */
    private function geoConstantsFor(string $customerId, Targeting $targeting): array
    {
        $constants = [];

        // Regions and cities are already opaque provider identifiers in the
        // platform's targeting, which for Google means constant ids.
        foreach ([...$targeting->regions, ...$targeting->cities] as $identifier) {
            $constants[] = str_contains($identifier, '/')
                ? $identifier
                : 'geoTargetConstants/'.$identifier;
        }

        if ($targeting->countries !== []) {
            $quoted = implode(',', array_map(
                static fn (string $code): string => "'".GoogleAdsClient::escape($code)."'",
                $targeting->countries,
            ));

            $rows = $this->platformClient()->search(
                $customerId,
                'SELECT geo_target_constant.id, geo_target_constant.resource_name, '
                .'geo_target_constant.country_code '
                .'FROM geo_target_constant '
                ."WHERE geo_target_constant.country_code IN ({$quoted}) "
                ."AND geo_target_constant.target_type = 'Country' "
                ."AND geo_target_constant.status = 'ENABLED'",
                pageSize: 200,
                maxPages: 1,
            );

            $resolved = [];

            foreach ($rows as $row) {
                $constant = is_array($row['geoTargetConstant'] ?? null) ? $row['geoTargetConstant'] : [];
                $code = isset($constant['countryCode']) ? strtoupper((string) $constant['countryCode']) : null;
                $name = $constant['resourceName'] ?? null;

                if ($code !== null && is_string($name) && $name !== '') {
                    $resolved[$code] = $name;
                }
            }

            foreach ($targeting->countries as $code) {
                if (! isset($resolved[$code])) {
                    throw ProviderUnavailable::refused(
                        Provider::Google,
                        "Google has no country target for [{$code}]",
                        "Google Ads does not recognise [{$code}] as a country we can target. "
                        .'Please change the audience.',
                    );
                }

                $constants[] = $resolved[$code];
            }
        }

        return array_values(array_unique($constants));
    }

    /**
     * @param  list<string>  $languages
     * @return list<string>
     *
     * @throws ProviderUnavailable
     */
    private function languageConstantsFor(string $customerId, array $languages): array
    {
        $quoted = implode(',', array_map(
            static fn (string $code): string => "'".GoogleAdsClient::escape(strtolower($code))."'",
            $languages,
        ));

        $rows = $this->platformClient()->search(
            $customerId,
            'SELECT language_constant.id, language_constant.resource_name, language_constant.code '
            ."FROM language_constant WHERE language_constant.code IN ({$quoted})",
            pageSize: 200,
            maxPages: 1,
        );

        $resolved = [];

        foreach ($rows as $row) {
            $constant = is_array($row['languageConstant'] ?? null) ? $row['languageConstant'] : [];
            $code = isset($constant['code']) ? strtolower((string) $constant['code']) : null;
            $name = $constant['resourceName'] ?? null;

            if ($code !== null && is_string($name) && $name !== '') {
                $resolved[$code] = $name;
            }
        }

        $constants = [];

        foreach ($languages as $code) {
            $key = strtolower($code);

            if (! isset($resolved[$key])) {
                throw ProviderUnavailable::refused(
                    Provider::Google,
                    "Google has no language constant for [{$code}]",
                    "Google Ads does not recognise [{$code}] as a language we can target. "
                    .'Please change the audience.',
                );
            }

            $constants[] = $resolved[$key];
        }

        return array_values(array_unique($constants));
    }

    /**
     * Refuse targeting a search campaign cannot express.
     *
     * Google offers interest, gender, age and device targeting on a search
     * campaign only as *bid adjustments* — they change what is paid, not who
     * sees the ad. Accepting a client's narrowing and then not applying it
     * would spend their budget on an audience they explicitly excluded, and
     * they would have no way to tell from either interface that it had
     * happened. So it is refused, naming the dimension (§27, §87).
     *
     * @throws ProviderUnavailable
     */
    private function assertTargetingIsExpressible(Targeting $targeting): void
    {
        $everyone = Targeting::everyone();

        $unsupported = array_keys(array_filter([
            'interests' => $targeting->interests !== [],
            'excluded interests' => $targeting->excludedInterests !== [],
            'gender' => $targeting->genders !== [],
            'devices' => $targeting->devices !== [],
            'age range' => $targeting->minimumAge > $everyone->minimumAge
                || $targeting->maximumAge < $everyone->maximumAge,
        ]));

        if ($unsupported === []) {
            return;
        }

        $named = implode(', ', $unsupported);

        throw ProviderUnavailable::refused(
            Provider::Google,
            "a Google search campaign cannot target on: {$named}",
            "Google Ads search campaigns cannot narrow an audience by {$named}. "
            .'Please remove it from this audience, or run this campaign on a provider that supports it.',
        );
    }

    // ------------------------------------------------------------------
    // Ad copy
    // ------------------------------------------------------------------

    /**
     * Google's own limits on responsive search ad copy.
     */
    private const HEADLINE_LIMIT = 30;

    private const DESCRIPTION_LIMIT = 90;

    private const MIN_HEADLINES = 3;

    private const MAX_HEADLINES = 15;

    private const MIN_DESCRIPTIONS = 2;

    private const MAX_DESCRIPTIONS = 4;

    /**
     * The headlines Google will rotate.
     *
     * Copy over the limit is left out rather than cut short: a headline
     * truncated mid-word is the client's brand saying something they did not
     * write. If that leaves too few, the ad is refused with a message naming
     * both the minimum and the character limit, which is something a person
     * can act on.
     *
     * @return list<string>
     *
     * @throws ProviderUnavailable
     */
    private function headlinesFor(AdDraft $draft): array
    {
        $candidates = $this->fit(
            [$draft->headline, ...$draft->extraHeadlines],
            self::HEADLINE_LIMIT,
        );

        if (count($candidates) < self::MIN_HEADLINES) {
            throw ProviderUnavailable::refused(
                Provider::Google,
                'the ad carries '.count($candidates).' usable headlines',
                'Google Ads needs at least '.self::MIN_HEADLINES.' different headlines of '
                .self::HEADLINE_LIMIT.' characters or fewer for each ad. Please add more.',
            );
        }

        return array_slice($candidates, 0, self::MAX_HEADLINES);
    }

    /**
     * @return list<string>
     *
     * @throws ProviderUnavailable
     */
    private function descriptionsFor(AdDraft $draft): array
    {
        /*
         * The primary text is offered as a description because that is the
         * nearest thing a search ad has to it — but only when it fits. A
         * paragraph written for a Meta feed does not become a Google
         * description by being cut down to ninety characters.
         */
        $candidates = $this->fit(
            array_values(array_filter([
                $draft->description,
                $draft->primaryText,
                ...$draft->extraDescriptions,
            ], static fn (?string $text): bool => $text !== null)),
            self::DESCRIPTION_LIMIT,
        );

        if (count($candidates) < self::MIN_DESCRIPTIONS) {
            throw ProviderUnavailable::refused(
                Provider::Google,
                'the ad carries '.count($candidates).' usable descriptions',
                'Google Ads needs at least '.self::MIN_DESCRIPTIONS.' different descriptions of '
                .self::DESCRIPTION_LIMIT.' characters or fewer for each ad. Please add more.',
            );
        }

        return array_slice($candidates, 0, self::MAX_DESCRIPTIONS);
    }

    /**
     * Trimmed, de-duplicated, and only what fits.
     *
     * @param  list<string>  $texts
     * @return list<string>
     */
    private function fit(array $texts, int $limit): array
    {
        $kept = [];

        foreach ($texts as $text) {
            $trimmed = trim($text);

            if ($trimmed === '' || mb_strlen($trimmed) > $limit) {
                continue;
            }

            // Google rejects an ad with two identical headlines, and a
            // duplicate would not have added a combination anyway.
            if (! in_array($trimmed, $kept, true)) {
                $kept[] = $trimmed;
            }
        }

        return $kept;
    }

    // ------------------------------------------------------------------
    // Names, identifiers and figures
    // ------------------------------------------------------------------

    private function marker(string $reference): string
    {
        return self::REFERENCE_PREFIX.$reference;
    }

    private function carriesMarker(mixed $name, string $marker): bool
    {
        return is_string($name) && str_contains($name, $marker);
    }

    /**
     * The platform's reference, carried where Google will give it back.
     *
     * Truncated so the whole name stays inside Google's limit; the reference
     * is a ULID and is never abbreviated, because a truncated reference would
     * match the wrong object.
     */
    private function nameFor(string $name, string $reference): string
    {
        $suffix = ' ['.$this->marker($reference).']';
        $room = self::MAX_NAME_LENGTH - strlen($suffix);

        return mb_substr($name, 0, max(1, $room)).$suffix;
    }

    /**
     * @throws ProviderUnavailable
     */
    private function customerId(AdAccount $account): string
    {
        $digits = GoogleAdsConfig::digits($account->external_account_id);

        if ($digits === null) {
            throw ProviderUnavailable::refused(
                Provider::Google,
                "[{$account->external_account_id}] is not a Google Ads customer id",
                'This advertising account is not set up correctly. Our team has been notified.',
            );
        }

        return $digits;
    }

    /**
     * Campaigns are addressed by resource name — `customers/1/campaigns/2` —
     * and that is what the platform stores. A bare id is accepted too, because
     * an account registered before this adapter existed may hold one.
     */
    private function campaignResourceName(string $customerId, string $external): string
    {
        return str_contains($external, '/')
            ? $external
            : "customers/{$customerId}/campaigns/".(GoogleAdsConfig::digits($external) ?? $external);
    }

    private function adGroupResourceName(string $customerId, string $external): string
    {
        return str_contains($external, '/')
            ? $external
            : "customers/{$customerId}/adGroups/".(GoogleAdsConfig::digits($external) ?? $external);
    }

    /**
     * @throws ProviderUnavailable
     */
    private function campaignId(string $external): string
    {
        return $this->resourceId($external);
    }

    /**
     * The numeric id at the end of a resource name, for a GAQL condition.
     *
     * @throws ProviderUnavailable
     */
    private function resourceId(string $external): string
    {
        $id = GoogleAdsConfig::digits(str_contains($external, '/') ? basename($external) : $external);

        if ($id === null) {
            throw ProviderUnavailable::refused(
                Provider::Google,
                "[{$external}] carries no Google identifier",
                'We could not find this in Google Ads.',
            );
        }

        return $id;
    }

    /**
     * @throws ProviderUnavailable
     */
    private function assertSameCurrency(string $accountCurrency, string $campaignCurrency): void
    {
        if (strtoupper($accountCurrency) === strtoupper($campaignCurrency)) {
            return;
        }

        /*
         * Refused rather than converted. Google spends in the ad account's own
         * currency, and a budget converted here would be a rate this platform
         * chose applied to a client's money without a record of it — which is
         * what the exchange-rate engine and the ledger exist to prevent
         * (Rule 8, §59).
         */
        throw ProviderUnavailable::refused(
            Provider::Google,
            "the campaign is in {$campaignCurrency} and the ad account bills in {$accountCurrency}",
            'This campaign and the advertising account it was assigned to use different currencies. '
            .'Our team has been notified.',
        );
    }

    /**
     * The fields of a campaign that its ad sets may need to change.
     *
     * @return array<string, mixed>
     *
     * @throws ProviderUnavailable
     */
    private function campaignFields(string $customerId, string $campaign): array
    {
        $row = $this->platformClient()->searchOne(
            $customerId,
            'SELECT campaign.id, campaign.bidding_strategy_type, campaign.start_date, campaign.end_date '
            .'FROM campaign WHERE campaign.id = '.$this->campaignId($campaign),
        );

        if ($row === null) {
            throw ProviderUnavailable::refused(
                Provider::Google,
                "Google returned no campaign for {$campaign}",
                'We could not find this campaign in Google Ads.',
            );
        }

        return is_array($row['campaign'] ?? null) ? $row['campaign'] : [];
    }

    /**
     * @throws ProviderUnavailable
     */
    private function campaignHasAdGroups(string $customerId, string $campaign): bool
    {
        return $this->platformClient()->searchOne(
            $customerId,
            'SELECT ad_group.id FROM ad_group WHERE campaign.id = '.$this->campaignId($campaign)
            ." AND ad_group.status != 'REMOVED'",
        ) !== null;
    }

    /**
     * The currency a target amount is expressed in.
     *
     * Read from the ad account rather than assumed, because a bid in micros is
     * scaled by the currency and getting it wrong is a hundredfold error.
     */
    private function currencyOf(string $customerId): string
    {
        $row = $this->platformClient()->searchOne(
            $customerId,
            'SELECT customer.currency_code FROM customer LIMIT 1',
        );

        $currency = $row['customer']['currencyCode'] ?? null;

        if (! is_string($currency) || ! Currency::supported($currency)) {
            throw ProviderUnavailable::refused(
                Provider::Google,
                'the customer reports no usable currency, so a bid cannot be converted',
                'We could not read the currency of this Google Ads account.',
            );
        }

        return $currency;
    }

    /**
     * Google counts conversions as a decimal — a click credited to two
     * campaigns counts half to each. The platform stores whole conversions,
     * so the figure is rounded rather than truncated: 0.6 of a conversion is
     * nearer one than none.
     *
     * @param  array<string, mixed>  $metrics
     */
    private function conversionsFrom(array $metrics): ?int
    {
        $conversions = $metrics['conversions'] ?? null;

        if ($conversions === null || ! is_numeric((string) $conversions)) {
            return null;
        }

        return (int) round((float) (string) $conversions);
    }

    /**
     * `metrics.conversions_value` is a decimal in the account's own currency,
     * not in micros — Google is inconsistent about this, and treating it as
     * micros would report a conversion worth ten thousand taka as worth one.
     */
    private function currencyToMinor(mixed $value, string $currency): ?int
    {
        if ($value === null || ! is_numeric((string) $value)) {
            return null;
        }

        try {
            $scale = Currency::of($currency)->scale;
        } catch (\InvalidArgumentException) {
            return null;
        }

        return (int) round(((float) (string) $value) * (10 ** $scale));
    }
}
