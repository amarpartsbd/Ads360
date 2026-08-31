<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Providers\Meta;

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
use DateTimeImmutable;

/**
 * Creating and controlling campaigns at Meta (spec §21, §26, Rule 17).
 *
 * ## How idempotency works here, and why it is not a header
 *
 * The Marketing API has no idempotency-key mechanism. Sending the same
 * campaign creation twice creates two campaigns, each with its own budget,
 * each spending the client's money. The contract's `$idempotencyKey` therefore
 * cannot simply be forwarded.
 *
 * What this adapter does instead:
 *
 *   1. Every object it creates carries the platform's own reference in its
 *      name, as a `[ads360:<reference>]` suffix. The reference is the
 *      campaign's, ad set's or ad's public id — stable across retries, unique
 *      across the platform.
 *   2. Before creating anything, it lists the parent's existing objects and
 *      looks for that reference. A match means a previous attempt already
 *      succeeded, and the existing object is returned with `wasExisting: true`
 *      rather than a second one being made.
 *
 * That is a real extra round trip on every creation. It is the price of a
 * provider without idempotency keys, and it is cheaper than the alternative:
 * the platform's publication ledger stops most duplicates, but a worker killed
 * between Meta acting and the ledger being written is exactly the case the
 * ledger cannot see — and this check can.
 *
 * The name suffix is visible to clients in Meta's own interface. That is a
 * deliberate trade: a slightly noisier campaign name in Ads Manager, in
 * exchange for never charging someone twice.
 */
trait MetaPublishesCampaigns
{
    /** How the platform's reference is embedded in a Meta object's name. */
    private const REFERENCE_PREFIX = 'ads360:';

    public function createCampaign(
        AdAccount $account,
        CampaignDraft $draft,
        string $idempotencyKey,
    ): PublishedEntity {
        $path = $this->accountPath($account->external_account_id);

        $existing = $this->existing($path.'/campaigns', $draft->reference);

        if ($existing !== null) {
            return $existing;
        }

        $payload = [
            'name' => $this->nameFor($draft->name, $draft->reference),
            'objective' => $this->objectiveFor($draft->objective),
            // Created paused, always. A campaign that went live the instant it
            // was created would start spending before its ad sets and ads
            // existed, on a structure that is not yet what was approved.
            'status' => 'PAUSED',
            'special_ad_categories' => json_encode([]),
        ];

        if ($draft->budgetType === BudgetType::Lifetime) {
            $payload['lifetime_budget'] = $draft->budgetMinor;
        } else {
            $payload['daily_budget'] = $draft->budgetMinor;
        }

        $created = $this->client->post($path.'/campaigns', $payload);

        return $this->published($created, $draft->reference);
    }

    public function createAdSet(
        AdAccount $account,
        AdSetDraft $draft,
        string $idempotencyKey,
    ): PublishedEntity {
        $path = $this->accountPath($account->external_account_id);

        $existing = $this->existing($path.'/adsets', $draft->reference);

        if ($existing !== null) {
            return $existing;
        }

        $payload = [
            'name' => $this->nameFor($draft->name, $draft->reference),
            'campaign_id' => $draft->externalCampaignId,
            'status' => 'PAUSED',
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => $draft->optimizationGoal ?? 'LINK_CLICKS',
            'bid_strategy' => $this->bidStrategyFor($draft->bidStrategy),
            'targeting' => json_encode($this->targetingFor($draft->targeting)),
        ];

        if ($draft->bidAmountMinor !== null) {
            $payload['bid_amount'] = $draft->bidAmountMinor;
        }

        if ($draft->budgetMinor !== null) {
            $payload['daily_budget'] = $draft->budgetMinor;
        }

        if ($draft->startsAt !== null) {
            $payload['start_time'] = $draft->startsAt->format(DATE_ATOM);
        }

        if ($draft->endsAt !== null) {
            $payload['end_time'] = $draft->endsAt->format(DATE_ATOM);
        }

        $created = $this->client->post($path.'/adsets', $payload);

        return $this->published($created, $draft->reference);
    }

    /**
     * Creating an ad at Meta is three calls, not one: upload the image, make a
     * creative that references it, then make the ad. Each is separately
     * fallible, and the image upload is deduplicated by Meta itself — the same
     * bytes return the same hash — so a retry does not accumulate copies.
     */
    public function createAd(
        AdAccount $account,
        AdDraft $draft,
        string $idempotencyKey,
    ): PublishedEntity {
        $path = $this->accountPath($account->external_account_id);

        $existing = $this->existing($path.'/ads', $draft->reference);

        if ($existing !== null) {
            return $existing;
        }

        $imageHash = $this->uploadImage($path, $draft);
        $creativeId = $this->createCreative($path, $draft, $imageHash);

        $created = $this->client->post($path.'/ads', [
            'name' => $this->nameFor($draft->name, $draft->reference),
            'adset_id' => $draft->externalAdSetId,
            'creative' => json_encode(['creative_id' => $creativeId]),
            'status' => 'PAUSED',
        ]);

        return $this->published($created, $draft->reference);
    }

    public function setCampaignActive(
        AdAccount $account,
        string $externalCampaignId,
        bool $active,
        string $idempotencyKey,
    ): void {
        // Repeats are harmless: Meta accepts a status it is already in.
        $this->client->post($externalCampaignId, [
            'status' => $active ? 'ACTIVE' : 'PAUSED',
        ]);
    }

    /**
     * Meta's terminal state for a campaign is ARCHIVED. Deleting is available
     * and is not used: an archived campaign keeps its history, and its spend
     * still has to reconcile against the client's ledger (§62).
     */
    public function stopCampaign(
        AdAccount $account,
        string $externalCampaignId,
        string $idempotencyKey,
    ): void {
        $this->client->post($externalCampaignId, ['status' => 'ARCHIVED']);
    }

    public function campaignInsights(AdAccount $account, string $externalCampaignId): CampaignInsights
    {
        $status = $this->client->get($externalCampaignId, ['fields' => 'status,effective_status']);

        $rows = $this->client->get($externalCampaignId.'/insights', [
            'fields' => 'spend,impressions,clicks,actions',
            // Lifetime, because the ledger reconciles against spend-to-date
            // rather than against a window.
            'date_preset' => 'maximum',
        ]);

        $row = $rows['data'][0] ?? null;

        if (! is_array($row)) {
            // No rows means the campaign has not spent yet. That is genuinely
            // "nothing reported", not zero — a campaign published minutes ago
            // should not have its hold released as though it had finished.
            return new CampaignInsights(
                externalCampaignId: $externalCampaignId,
                status: $this->effectiveStatus($status),
                raw: ['insights' => 'empty'],
            );
        }

        return new CampaignInsights(
            externalCampaignId: $externalCampaignId,
            spendMinor: $this->spendToMinor($row['spend'] ?? null, $account->currency),
            currency: $account->currency,
            impressions: isset($row['impressions']) ? (int) $row['impressions'] : null,
            clicks: isset($row['clicks']) ? (int) $row['clicks'] : null,
            conversions: $this->conversionsFrom($row),
            status: $this->effectiveStatus($status),
            raw: ['spend' => $row['spend'] ?? null],
        );
    }

    /**
     * Day-by-day performance over a window (spec §38, §78).
     *
     * `time_increment=1` is what makes Meta return one row per day rather than
     * one row for the whole range. The window is re-fetched rather than only
     * extended, because Meta restates: a conversion attributed days after the
     * click lands on the day of the click, changing a figure already reported.
     *
     * @return list<DailyInsightRow>
     */
    public function campaignDailyInsights(
        AdAccount $account,
        string $externalCampaignId,
        DateTimeImmutable $since,
        DateTimeImmutable $until,
    ): array {
        $rows = $this->client->paginate($externalCampaignId.'/insights', [
            'fields' => 'date_start,spend,impressions,clicks,reach,actions,action_values',
            'time_increment' => 1,
            'time_range' => json_encode([
                'since' => $since->format('Y-m-d'),
                'until' => $until->format('Y-m-d'),
            ]),
            'limit' => 100,
        ]);

        $insights = [];

        foreach ($rows as $row) {
            $date = isset($row['date_start']) ? (string) $row['date_start'] : null;

            if ($date === null) {
                continue;
            }

            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed === false) {
                continue;
            }

            $insights[] = new DailyInsightRow(
                date: $parsed,
                spendMinor: $this->spendToMinor($row['spend'] ?? null, $account->currency),
                currency: $account->currency,
                impressions: isset($row['impressions']) ? (int) $row['impressions'] : null,
                clicks: isset($row['clicks']) ? (int) $row['clicks'] : null,
                reach: isset($row['reach']) ? (int) $row['reach'] : null,
                conversions: $this->conversionsFrom($row),
                conversionValueMinor: $this->conversionValueFrom($row, $account->currency),
                raw: ['date_start' => $date],
            );
        }

        return $insights;
    }

    // ------------------------------------------------------------------
    // Idempotency without a key header
    // ------------------------------------------------------------------

    /**
     * Look for an object this platform already created for a reference.
     *
     * Bounded to the most recent objects on the edge: a retry follows its
     * original attempt within minutes, so an unbounded search would cost far
     * more than it could ever find.
     *
     * @throws ProviderUnavailable
     */
    private function existing(string $edge, string $reference): ?PublishedEntity
    {
        $marker = self::REFERENCE_PREFIX.$reference;

        $nodes = $this->client->paginate(
            $edge,
            ['fields' => 'id,name,status', 'limit' => 100],
            maxPages: 3,
        );

        foreach ($nodes as $node) {
            if (! is_string($node['name'] ?? null) || ! str_contains($node['name'], $marker)) {
                continue;
            }

            return new PublishedEntity(
                externalId: (string) ($node['id'] ?? ''),
                status: isset($node['status']) ? (string) $node['status'] : null,
                // The signal that stops the publication ledger recording a
                // second creation as though it were the first.
                wasExisting: true,
                raw: ['matched_on' => 'name_reference'],
            );
        }

        return null;
    }

    /**
     * The platform's reference, carried where Meta will give it back.
     *
     * Truncated so the whole name stays inside Meta's limit; the reference is
     * a ULID and is never abbreviated, because a truncated reference would
     * match the wrong object.
     */
    private function nameFor(string $name, string $reference): string
    {
        $suffix = ' ['.self::REFERENCE_PREFIX.$reference.']';
        $room = 400 - strlen($suffix);

        return mb_substr($name, 0, max(1, $room)).$suffix;
    }

    /**
     * @param  array<string, mixed>  $created
     */
    private function published(array $created, string $reference): PublishedEntity
    {
        $id = (string) ($created['id'] ?? '');

        if ($id === '') {
            throw ProviderUnavailable::transient(
                Provider::Meta,
                'the create call returned no identifier for '.$reference,
            );
        }

        return new PublishedEntity(externalId: $id, status: 'PAUSED', wasExisting: false);
    }

    // ------------------------------------------------------------------
    // Creative
    // ------------------------------------------------------------------

    /**
     * Meta keys uploaded images by a hash of their bytes, so uploading the
     * same file twice is free and returns the same hash. That makes this step
     * naturally safe to retry.
     */
    private function uploadImage(string $accountPath, AdDraft $draft): string
    {
        $stream = $draft->creativeStream();

        if (! is_resource($stream)) {
            throw ProviderUnavailable::refused(
                Provider::Meta,
                'the ad has no readable creative',
                'We could not read the image for this ad. Please upload it again.',
            );
        }

        try {
            $response = $this->client->upload(
                $accountPath.'/adimages',
                'source',
                $stream,
                $draft->creativeChecksum.'.jpg',
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $images = $response['images'] ?? [];
        $first = is_array($images) ? (reset($images) ?: []) : [];
        $hash = is_array($first) ? (string) ($first['hash'] ?? '') : '';

        if ($hash === '') {
            throw ProviderUnavailable::transient(
                Provider::Meta,
                'the image upload returned no hash',
            );
        }

        return $hash;
    }

    private function createCreative(string $accountPath, AdDraft $draft, string $imageHash): string
    {
        $created = $this->client->post($accountPath.'/adcreatives', [
            'name' => $this->nameFor($draft->name.' creative', $draft->reference),
            'object_story_spec' => json_encode([
                // The client's own page, never the platform's.
                'page_id' => $draft->identityExternalId,
                'link_data' => array_filter([
                    'image_hash' => $imageHash,
                    'link' => $draft->destinationUrl,
                    'message' => $draft->primaryText,
                    'name' => $draft->headline,
                    'description' => $draft->description,
                    'call_to_action' => $draft->callToAction === null
                        ? null
                        : ['type' => $draft->callToAction],
                ], static fn ($value): bool => $value !== null),
            ]),
            'degrees_of_freedom_spec' => json_encode([
                // Meta may otherwise reframe or restyle a creative on its own.
                // The client approved specific copy and a specific image, so
                // the platform does not let it be changed after review.
                'creative_features_spec' => ['standard_enhancements' => ['enroll_status' => 'OPT_OUT']],
            ]),
        ]);

        $id = (string) ($created['id'] ?? '');

        if ($id === '') {
            throw ProviderUnavailable::transient(Provider::Meta, 'the creative call returned no identifier');
        }

        return $id;
    }

    // ------------------------------------------------------------------
    // Vocabulary translation
    // ------------------------------------------------------------------

    /** The platform's objectives in Meta's current dialect. */
    private function objectiveFor(CampaignObjective $objective): string
    {
        return match ($objective) {
            CampaignObjective::Awareness => 'OUTCOME_AWARENESS',
            CampaignObjective::Traffic => 'OUTCOME_TRAFFIC',
            CampaignObjective::Engagement => 'OUTCOME_ENGAGEMENT',
            CampaignObjective::Leads => 'OUTCOME_LEADS',
            CampaignObjective::AppPromotion => 'OUTCOME_APP_PROMOTION',
            CampaignObjective::Sales => 'OUTCOME_SALES',
        };
    }

    private function bidStrategyFor(BidStrategy $strategy): string
    {
        return match ($strategy) {
            BidStrategy::LowestCost => 'LOWEST_COST_WITHOUT_CAP',
            BidStrategy::CostCap => 'COST_CAP',
            BidStrategy::BidCap => 'LOWEST_COST_WITH_BID_CAP',
            BidStrategy::TargetCost => 'LOWEST_COST_WITH_MIN_ROAS',
        };
    }

    /**
     * Targeting in Meta's shape.
     *
     * Only what the platform's own value object holds is sent. Meta accepts a
     * great deal more, and the ones it accepts that the platform does not
     * offer — the protected characteristics — are absent here because they are
     * absent there (§27).
     *
     * @return array<string, mixed>
     */
    private function targetingFor(Targeting $targeting): array
    {
        $geo = array_filter([
            'countries' => $targeting->countries !== [] ? $targeting->countries : null,
            'regions' => $targeting->regions !== []
                ? array_map(static fn (string $key): array => ['key' => $key], $targeting->regions)
                : null,
            'cities' => $targeting->cities !== []
                ? array_map(static fn (string $key): array => ['key' => $key], $targeting->cities)
                : null,
        ]);

        $spec = [
            'geo_locations' => $geo,
            'age_min' => $targeting->minimumAge,
            'age_max' => $targeting->maximumAge,
        ];

        if ($targeting->genders !== []) {
            // Meta encodes gender as 1 for male and 2 for female.
            $spec['genders'] = array_values(array_map(
                static fn (string $gender): int => $gender === 'male' ? 1 : 2,
                $targeting->genders,
            ));
        }

        if ($targeting->interests !== []) {
            $spec['flexible_spec'] = [[
                'interests' => array_map(
                    static fn (string $id): array => ['id' => $id],
                    $targeting->interests,
                ),
            ]];
        }

        if ($targeting->excludedInterests !== []) {
            $spec['exclusions'] = [
                'interests' => array_map(
                    static fn (string $id): array => ['id' => $id],
                    $targeting->excludedInterests,
                ),
            ];
        }

        if ($targeting->devices !== []) {
            $spec['device_platforms'] = array_values(array_map(
                static fn (string $device): string => $device === 'desktop' ? 'desktop' : 'mobile',
                $targeting->devices,
            ));
        }

        if ($targeting->customAudiences !== []) {
            $spec['custom_audiences'] = array_map(
                static fn (string $id): array => ['id' => $id],
                $targeting->customAudiences,
            );
        }

        return $spec;
    }

    /**
     * Meta reports spend as a decimal string in the account's currency. It has
     * to be scaled by the currency's own exponent, because a taka figure and a
     * yen figure are not the same shape.
     */
    private function spendToMinor(mixed $spend, string $currency): ?int
    {
        if ($spend === null || ! is_numeric((string) $spend)) {
            return null;
        }

        try {
            $scale = \App\Support\Values\Currency::of($currency)->scale;
        } catch (\InvalidArgumentException) {
            return null;
        }

        return (int) round(((float) (string) $spend) * (10 ** $scale));
    }

    /**
     * What the conversions were worth, when Meta reports it. Same currency as
     * the account, and the same decimal-to-minor conversion as spend.
     *
     * @param  array<string, mixed>  $row
     */
    private function conversionValueFrom(array $row, string $currency): ?int
    {
        $values = $row['action_values'] ?? null;

        if (! is_array($values)) {
            return null;
        }

        foreach ($values as $value) {
            if (is_array($value) && ($value['action_type'] ?? null) === 'purchase') {
                return $this->spendToMinor($value['value'] ?? null, $currency);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function conversionsFrom(array $row): ?int
    {
        $actions = $row['actions'] ?? null;

        if (! is_array($actions)) {
            return null;
        }

        foreach ($actions as $action) {
            if (is_array($action) && ($action['action_type'] ?? null) === 'purchase') {
                return (int) ($action['value'] ?? 0);
            }
        }

        return null;
    }

    /**
     * `effective_status` is the one that accounts for a parent being paused or
     * an ad being rejected; `status` alone would report a campaign as active
     * while nothing under it could run.
     *
     * @param  array<string, mixed>  $status
     */
    private function effectiveStatus(array $status): ?string
    {
        $effective = $status['effective_status'] ?? $status['status'] ?? null;

        return is_string($effective) ? $effective : null;
    }
}
