<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising\Google;

use App\Domains\Advertising\DTOs\AdDraft;
use App\Domains\Advertising\DTOs\AdSetDraft;
use App\Domains\Advertising\DTOs\CampaignDraft;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Campaign\Enums\BidStrategy;
use App\Domains\Campaign\Enums\BudgetType;
use App\Domains\Campaign\Enums\CampaignObjective;
use App\Domains\Campaign\Values\Targeting;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesGoogleAds;
use Tests\TestCase;

/**
 * Publishing to Google Ads (spec §21, §26, §27, Rule 17).
 *
 * Two groups of test matter most here.
 *
 * The **idempotency** ones, because a lost race that creates a second campaign
 * creates a second budget and spends a client's money twice. Google enforces
 * name uniqueness itself, and these prove the adapter uses that rather than
 * only hoping its own lookup won.
 *
 * The **refusal** ones, because the alternative to refusing is worse than a
 * failed publish: accepting a narrowing Google cannot apply would spend a
 * client's budget on an audience they explicitly excluded, and neither
 * interface would show that it had happened.
 */
final class GooglePublishingTest extends TestCase
{
    use FakesGoogleAds;
    use RefreshDatabase;

    private ?AdAccount $account = null;

    private const CAMPAIGN = 'customers/1234567890/campaigns/555000111';

    private const AD_GROUP = 'customers/1234567890/adGroups/777000222';

    /**
     * Memoised: the inventory refuses two rows for one provider account, and
     * a test wanting the account twice means the same account both times.
     */
    private function account(): AdAccount
    {
        return $this->account ??= AdAccount::factory()->create([
            'provider' => Provider::Google,
            'external_account_id' => '1234567890',
            'currency' => 'BDT',
        ]);
    }

    private function campaignDraft(
        string $reference = '01JCAMPAIGN0000000000000001',
        BudgetType $budgetType = BudgetType::Daily,
        int $budgetMinor = 250_000,
        ?DateTimeImmutable $endsAt = null,
    ): CampaignDraft {
        return new CampaignDraft(
            reference: $reference,
            name: 'Eid Collection',
            objective: CampaignObjective::Traffic,
            budgetType: $budgetType,
            budgetMinor: $budgetMinor,
            currency: 'BDT',
            startsAt: new DateTimeImmutable('2026-09-01'),
            endsAt: $endsAt,
        );
    }

    private function adSetDraft(?Targeting $targeting = null, BidStrategy $bid = BidStrategy::LowestCost): AdSetDraft
    {
        return new AdSetDraft(
            reference: '01JADSET00000000000000001',
            externalCampaignId: self::CAMPAIGN,
            name: 'Dhaka shoppers',
            targeting: $targeting ?? Targeting::fromArray(['countries' => ['BD']]),
            bidStrategy: $bid,
        );
    }

    /**
     * @param  list<string>  $extraHeadlines
     * @param  list<string>  $extraDescriptions
     */
    private function adDraft(array $extraHeadlines = [], array $extraDescriptions = []): AdDraft
    {
        return new AdDraft(
            reference: '01JAD000000000000000000001',
            externalAdSetId: self::AD_GROUP,
            name: 'Eid ad',
            headline: 'Genuine parts, fast',
            primaryText: 'Order before six and it arrives tomorrow.',
            destinationUrl: 'https://amarparts.test/eid',
            identityExternalId: '1234567890',
            creativeChecksum: 'abc123',
            description: 'Thousands of parts in stock today.',
            extraHeadlines: $extraHeadlines,
            extraDescriptions: $extraDescriptions,
        );
    }

    // ------------------------------------------------------------------
    // Campaign creation
    // ------------------------------------------------------------------

    #[Test]
    public function a_campaign_is_created_paused_so_it_cannot_spend_before_its_ads_exist(): void
    {
        $this->fakeGoogle([
            '*googleAds:search*' => Http::response($this->googleSearch([])),
            '*campaignBudgets:mutate*' => Http::response($this->googleMutated('customers/1234567890/campaignBudgets/1')),
            '*campaigns:mutate*' => Http::response($this->googleMutated(self::CAMPAIGN)),
        ]);

        $this->googleAdapter()->createCampaign($this->account(), $this->campaignDraft(), 'key-1');

        $create = $this->campaignCreatePayload();

        // A campaign that went live on creation would start spending on a
        // structure that is not yet what was approved.
        $this->assertSame('PAUSED', $create['status'] ?? null);
    }

    #[Test]
    public function a_budget_is_created_before_the_campaign_that_points_at_it(): void
    {
        $this->fakeGoogle([
            '*googleAds:search*' => Http::response($this->googleSearch([])),
            '*campaignBudgets:mutate*' => Http::response($this->googleMutated('customers/1234567890/campaignBudgets/1')),
            '*campaigns:mutate*' => Http::response($this->googleMutated(self::CAMPAIGN)),
        ]);

        $this->googleAdapter()->createCampaign($this->account(), $this->campaignDraft(), 'key-1');

        // Google models a budget as an object the campaign references, so
        // publishing is two creations rather than one.
        $this->assertSame(
            'customers/1234567890/campaignBudgets/1',
            $this->campaignCreatePayload()['campaignBudget'] ?? null,
        );
    }

    #[Test]
    public function the_budget_is_sent_in_micros_scaled_by_the_currency(): void
    {
        $this->fakeGoogle([
            '*googleAds:search*' => Http::response($this->googleSearch([])),
            '*campaignBudgets:mutate*' => Http::response($this->googleMutated('customers/1234567890/campaignBudgets/1')),
            '*campaigns:mutate*' => Http::response($this->googleMutated(self::CAMPAIGN)),
        ]);

        // 250,000 poisha is 2,500 taka is 2.5 billion micros.
        $this->googleAdapter()->createCampaign($this->account(), $this->campaignDraft(), 'key-1');

        $this->assertSame('2500000000', $this->budgetCreatePayload()['amountMicros'] ?? null);
    }

    #[Test]
    public function a_lifetime_budget_is_spread_across_the_run_and_never_rounded_up(): void
    {
        $this->fakeGoogle([
            '*googleAds:search*' => Http::response($this->googleSearch([])),
            '*campaignBudgets:mutate*' => Http::response($this->googleMutated('customers/1234567890/campaignBudgets/1')),
            '*campaigns:mutate*' => Http::response($this->googleMutated(self::CAMPAIGN)),
        ]);

        // 1,000 taka across three days: 333.33 taka a day, floored.
        $this->googleAdapter()->createCampaign(
            $this->account(),
            $this->campaignDraft(
                budgetType: BudgetType::Lifetime,
                budgetMinor: 100_000,
                endsAt: new DateTimeImmutable('2026-09-03'),
            ),
            'key-1',
        );

        $micros = (int) $this->budgetCreatePayload()['amountMicros'];

        /*
         * Floored, not rounded: the derived daily figure times the number of
         * days can never exceed what the client reserved (Rule 8).
         */
        $this->assertSame(333_333_333, $micros);
        $this->assertLessThanOrEqual(1_000_000_000, $micros * 3);
    }

    #[Test]
    public function a_lifetime_budget_with_no_end_date_is_refused_rather_than_guessed_at(): void
    {
        $this->fakeGoogle(['*' => Http::response($this->googleSearch([]))]);

        try {
            $this->googleAdapter()->createCampaign(
                $this->account(),
                $this->campaignDraft(budgetType: BudgetType::Lifetime),
                'key-1',
            );
            $this->fail('The campaign should have been refused.');
        } catch (ProviderUnavailable $exception) {
            // Inventing a run length would be the platform deciding how fast a
            // client's money is spent.
            $this->assertStringContainsString('end date', $exception->clientMessage);
        }
    }

    #[Test]
    public function a_campaign_in_a_different_currency_from_its_ad_account_is_refused(): void
    {
        $this->fakeGoogle(['*' => Http::response($this->googleSearch([]))]);

        $draft = new CampaignDraft(
            reference: '01JCAMPAIGN0000000000000009',
            name: 'Cross-currency',
            objective: CampaignObjective::Traffic,
            budgetType: BudgetType::Daily,
            budgetMinor: 250_000,
            currency: 'USD',
        );

        try {
            $this->googleAdapter()->createCampaign($this->account(), $draft, 'key-1');
            $this->fail('The campaign should have been refused.');
        } catch (ProviderUnavailable $exception) {
            // Converting here would apply a rate the platform chose to a
            // client's money with no record of it (Rule 8, §59).
            $this->assertStringContainsString('different currencies', $exception->clientMessage);
        }
    }

    #[Test]
    public function the_display_network_is_not_switched_on_behind_a_search_budget(): void
    {
        $this->fakeGoogle([
            '*googleAds:search*' => Http::response($this->googleSearch([])),
            '*campaignBudgets:mutate*' => Http::response($this->googleMutated('customers/1234567890/campaignBudgets/1')),
            '*campaigns:mutate*' => Http::response($this->googleMutated(self::CAMPAIGN)),
        ]);

        $this->googleAdapter()->createCampaign($this->account(), $this->campaignDraft(), 'key-1');

        // Google turns this on by default, which spends part of a search
        // budget on placements the client never chose.
        $this->assertFalse($this->campaignCreatePayload()['networkSettings']['targetContentNetwork']);
    }

    #[Test]
    public function an_objective_the_adapter_cannot_publish_is_refused_rather_than_defaulted(): void
    {
        $this->fakeGoogle([
            '*googleAds:search*' => Http::response($this->googleSearch([])),
            '*campaignBudgets:mutate*' => Http::response($this->googleMutated('customers/1234567890/campaignBudgets/1')),
        ]);

        $draft = new CampaignDraft(
            reference: '01JCAMPAIGN0000000000000007',
            name: 'Brand push',
            objective: CampaignObjective::Awareness,
            budgetType: BudgetType::Daily,
            budgetMinor: 250_000,
            currency: 'BDT',
        );

        // Defaulting would have the campaign bid for something nobody chose.
        $this->expectException(ProviderUnavailable::class);

        $this->googleAdapter()->createCampaign($this->account(), $draft, 'key-1');
    }

    // ------------------------------------------------------------------
    // The platform's own grant
    // ------------------------------------------------------------------

    #[Test]
    public function publishing_authenticates_as_the_platform_not_as_nobody(): void
    {
        $this->fakeGoogle([
            '*googleAds:search*' => Http::response($this->googleSearch([])),
            '*campaignBudgets:mutate*' => Http::response($this->googleMutated('customers/1234567890/campaignBudgets/1')),
            '*campaigns:mutate*' => Http::response($this->googleMutated(self::CAMPAIGN)),
        ]);

        $this->googleAdapter()->createCampaign($this->account(), $this->campaignDraft(), 'key-1');

        /*
         * A managed ad account has no client grant behind it (spec §17), and
         * Google authenticates every call. An unauthenticated adapter would
         * fail every publish with a 401 that names nothing.
         */
        Http::assertSent(
            fn (Request $request): bool => str_contains($request->url(), ':mutate')
                && $request->hasHeader('Authorization', 'Bearer platform-access-token'),
        );
    }

    #[Test]
    public function the_platform_grant_is_exchanged_once_for_a_whole_publish(): void
    {
        $this->fakeGoogle([
            '*googleAds:search*' => Http::response($this->googleSearch([])),
            '*campaignBudgets:mutate*' => Http::response($this->googleMutated('customers/1234567890/campaignBudgets/1')),
            '*campaigns:mutate*' => Http::response($this->googleMutated(self::CAMPAIGN)),
        ]);

        $adapter = $this->googleAdapter();
        $adapter->createCampaign($this->account(), $this->campaignDraft(), 'key-1');
        $adapter->setCampaignActive($this->account(), self::CAMPAIGN, false, 'key-2');

        $exchanges = array_filter(
            $this->recordedGoogleRequests(),
            static fn ($request): bool => str_contains($request->url(), '/token'),
        );

        // Memoised for the life of the adapter, which is one request or one
        // job — and never written to a shared cache (Rule 12).
        $this->assertCount(1, $exchanges);
    }

    #[Test]
    public function a_missing_platform_grant_is_named_rather_than_left_as_a_401(): void
    {
        Http::fake(['*' => Http::response($this->googleSearch([]))]);

        $config = $this->googleConfig();

        $adapter = new \App\Domains\Advertising\Providers\Google\GoogleAdsProvider(
            $withoutGrant = new \App\Domains\Advertising\Providers\Google\GoogleAdsConfig(
                clientId: $config->clientId,
                clientSecret: $config->clientSecret,
                developerToken: $config->developerToken,
                apiVersion: $config->apiVersion,
                apiUrl: $config->apiUrl,
                authUrl: $config->authUrl,
                tokenUrl: $config->tokenUrl,
                userInfoUrl: $config->userInfoUrl,
                redirectUri: $config->redirectUri,
                scopes: $config->scopes,
                requestTimeout: $config->requestTimeout,
                connectTimeout: $config->connectTimeout,
                maxAttempts: $config->maxAttempts,
                retryDelayMilliseconds: $config->retryDelayMilliseconds,
                loginCustomerId: $config->loginCustomerId,
            ),
            new \App\Domains\Advertising\Providers\Google\GoogleAdsClient(
                $withoutGrant,
                new \App\Domains\Advertising\Providers\Google\GoogleAdsErrorMapper,
            ),
        );

        try {
            $adapter->createCampaign($this->account(), $this->campaignDraft(), 'key-1');
            $this->fail('Publishing without a platform grant should have failed.');
        } catch (ProviderUnavailable $exception) {
            $this->assertStringContainsString('GOOGLE_ADS_REFRESH_TOKEN', $exception->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Idempotency
    // ------------------------------------------------------------------

    #[Test]
    public function the_platform_reference_is_carried_in_the_name_google_stores(): void
    {
        $this->fakeGoogle([
            '*googleAds:search*' => Http::response($this->googleSearch([])),
            '*campaignBudgets:mutate*' => Http::response($this->googleMutated('customers/1234567890/campaignBudgets/1')),
            '*campaigns:mutate*' => Http::response($this->googleMutated(self::CAMPAIGN)),
        ]);

        $this->googleAdapter()->createCampaign($this->account(), $this->campaignDraft(), 'key-1');

        $this->assertStringContainsString(
            'ads360:01JCAMPAIGN0000000000000001',
            $this->campaignCreatePayload()['name'] ?? '',
        );
    }

    #[Test]
    public function a_campaign_that_already_carries_the_reference_is_returned_not_created_again(): void
    {
        $this->fakeGoogle([
            '*googleAds:search*' => Http::response($this->googleSearch([
                ['campaign' => [
                    'id' => '555000111',
                    'resourceName' => self::CAMPAIGN,
                    'name' => 'Eid Collection [ads360:01JCAMPAIGN0000000000000001]',
                    'status' => 'PAUSED',
                ]],
            ])),
        ]);

        $published = $this->googleAdapter()->createCampaign($this->account(), $this->campaignDraft(), 'key-1');

        $this->assertTrue($published->wasExisting);
        $this->assertSame(self::CAMPAIGN, $published->externalId);

        // Nothing was created: a second campaign means a second budget and a
        // client charged twice (Rule 17).
        $this->assertSame([], $this->sentMutations());
    }

    #[Test]
    public function a_campaign_bearing_someone_elses_reference_is_not_mistaken_for_ours(): void
    {
        $this->fakeGoogle([
            '*googleAds:search*' => Http::response($this->googleSearch([
                ['campaign' => [
                    'id' => '999',
                    'resourceName' => 'customers/1234567890/campaigns/999',
                    // A `LIKE` that matched loosely would return this one, and
                    // the platform would report a stranger's campaign as its own.
                    'name' => 'Someone else [ads360:01JCAMPAIGN0000000000000002]',
                    'status' => 'ENABLED',
                ]],
            ])),
            '*campaignBudgets:mutate*' => Http::response($this->googleMutated('customers/1234567890/campaignBudgets/1')),
            '*campaigns:mutate*' => Http::response($this->googleMutated(self::CAMPAIGN)),
        ]);

        $published = $this->googleAdapter()->createCampaign($this->account(), $this->campaignDraft(), 'key-1');

        $this->assertFalse($published->wasExisting);
        $this->assertSame(self::CAMPAIGN, $published->externalId);
    }

    #[Test]
    public function a_duplicate_name_refusal_recovers_the_campaign_the_first_attempt_made(): void
    {
        $this->fakeGoogle([
            '*googleAds:search*' => Http::sequence()
                // The pre-flight lookup finds nothing: another worker is mid-flight.
                ->push($this->googleSearch([]))
                // The budget lookup, likewise.
                ->push($this->googleSearch([]))
                // After Google refuses the duplicate, the campaign is there.
                ->push($this->googleSearch([
                    ['campaign' => [
                        'id' => '555000111',
                        'resourceName' => self::CAMPAIGN,
                        'name' => 'Eid Collection [ads360:01JCAMPAIGN0000000000000001]',
                        'status' => 'PAUSED',
                    ]],
                ])),
            '*campaignBudgets:mutate*' => Http::response($this->googleMutated('customers/1234567890/campaignBudgets/1')),
            '*campaigns:mutate*' => Http::response($this->googleError('campaignError', 'DUPLICATE_NAME'), 400),
        ]);

        $published = $this->googleAdapter()->createCampaign($this->account(), $this->campaignDraft(), 'key-1');

        /*
         * This is the guarantee Meta cannot offer. There, a lost race creates
         * a second campaign with a second budget; here Google refuses it, and
         * the refusal is proof the first attempt landed.
         */
        $this->assertTrue($published->wasExisting);
        $this->assertSame(self::CAMPAIGN, $published->externalId);
    }

    #[Test]
    public function a_budget_left_behind_by_a_failed_attempt_is_reused_not_orphaned(): void
    {
        $this->fakeGoogle([
            '*googleAds:search*' => Http::sequence()
                // No campaign yet.
                ->push($this->googleSearch([]))
                // But its budget was created before the failure.
                ->push($this->googleSearch([
                    ['campaignBudget' => [
                        'id' => '1',
                        'resourceName' => 'customers/1234567890/campaignBudgets/1',
                        'name' => 'Eid Collection budget [ads360:01JCAMPAIGN0000000000000001]',
                    ]],
                ])),
            '*campaigns:mutate*' => Http::response($this->googleMutated(self::CAMPAIGN)),
        ]);

        $this->googleAdapter()->createCampaign($this->account(), $this->campaignDraft(), 'key-1');

        $budgetMutations = array_filter(
            $this->sentMutations(),
            static fn (array $mutation): bool => str_contains($mutation['url'], 'campaignBudgets'),
        );

        $this->assertSame([], $budgetMutations);
    }

    // ------------------------------------------------------------------
    // Ad groups and targeting
    // ------------------------------------------------------------------

    #[Test]
    public function targeting_google_search_cannot_express_is_refused_rather_than_dropped(): void
    {
        $this->fakeGoogle(['*googleAds:search*' => Http::response($this->googleSearch([]))]);

        $targeting = Targeting::fromArray([
            'countries' => ['BD'],
            'genders' => ['female'],
            'interests' => ['1001'],
        ]);

        try {
            $this->googleAdapter()->createAdSet($this->account(), $this->adSetDraft($targeting), 'key-1');
            $this->fail('The ad set should have been refused.');
        } catch (ProviderUnavailable $exception) {
            /*
             * Accepting the narrowing and not applying it would spend the
             * client's budget on an audience they explicitly excluded, and
             * neither interface would show that it had happened (§27).
             */
            $this->assertStringContainsString('interests', $exception->clientMessage);
            $this->assertStringContainsString('gender', $exception->clientMessage);
        }
    }

    #[Test]
    public function a_narrowed_age_range_is_refused_on_a_search_campaign(): void
    {
        $this->fakeGoogle(['*googleAds:search*' => Http::response($this->googleSearch([]))]);

        $targeting = Targeting::fromArray([
            'countries' => ['BD'],
            'minimum_age' => 25,
            'maximum_age' => 45,
        ]);

        $this->expectException(ProviderUnavailable::class);

        $this->googleAdapter()->createAdSet($this->account(), $this->adSetDraft($targeting), 'key-1');
    }

    #[Test]
    public function the_platform_wide_age_range_is_not_treated_as_a_narrowing(): void
    {
        $this->fakeAdGroupCreation();

        $published = $this->googleAdapter()->createAdSet(
            $this->account(),
            $this->adSetDraft(Targeting::fromArray(['countries' => ['BD'], 'minimum_age' => 18, 'maximum_age' => 65])),
            'key-1',
        );

        $this->assertSame(self::AD_GROUP, $published->externalId);
    }

    #[Test]
    public function a_country_is_resolved_to_a_google_location_constant(): void
    {
        $this->fakeAdGroupCreation();

        $this->googleAdapter()->createAdSet($this->account(), $this->adSetDraft(), 'key-1');

        $criteria = $this->mutationBody('campaignCriteria:mutate');

        // Google targets by constant, not by ISO code.
        $this->assertSame(
            'geoTargetConstants/2050',
            $criteria['operations'][0]['create']['location']['geoTargetConstant'] ?? null,
        );
    }

    #[Test]
    public function a_country_google_does_not_recognise_is_refused_rather_than_dropped(): void
    {
        $this->fakeGoogle([
            '*googleAds:search*' => Http::sequence()
                // No existing ad group.
                ->push($this->googleSearch([]))
                // The campaign it belongs to.
                ->push($this->googleSearch([['campaign' => [
                    'id' => '555000111', 'biddingStrategyType' => 'TARGET_SPEND',
                ]]]))
                // The geo lookup comes back empty.
                ->push($this->googleSearch([])),
        ]);

        try {
            $this->googleAdapter()->createAdSet(
                $this->account(),
                $this->adSetDraft(Targeting::fromArray(['countries' => ['ZZ']])),
                'key-1',
            );
            $this->fail('The ad set should have been refused.');
        } catch (ProviderUnavailable $exception) {
            // Publishing without a narrowing the client asked for would spend
            // their budget somewhere they did not choose.
            $this->assertStringContainsString('ZZ', $exception->clientMessage);
        }
    }

    #[Test]
    public function an_ad_group_that_already_carries_the_reference_changes_nothing_on_its_campaign(): void
    {
        $this->fakeGoogle([
            '*googleAds:search*' => Http::response($this->googleSearch([
                ['adGroup' => [
                    'id' => '777000222',
                    'resourceName' => self::AD_GROUP,
                    'name' => 'Dhaka shoppers [ads360:01JADSET00000000000000001]',
                    'status' => 'PAUSED',
                ]],
            ])),
        ]);

        $published = $this->googleAdapter()->createAdSet($this->account(), $this->adSetDraft(), 'key-1');

        $this->assertTrue($published->wasExisting);

        /*
         * A retry that re-ran the campaign-level steps would find this ad
         * set's own work already in place and refuse it as a conflict.
         */
        $this->assertSame([], $this->sentMutations());
    }

    #[Test]
    public function a_second_audience_cannot_change_how_the_first_one_bids(): void
    {
        $this->fakeGoogle([
            '*googleAds:search*' => Http::sequence()
                // No ad group for this reference yet.
                ->push($this->googleSearch([]))
                // The campaign already bids automatically.
                ->push($this->googleSearch([['campaign' => [
                    'id' => '555000111',
                    'biddingStrategyType' => 'TARGET_SPEND',
                ]]]))
                // And it already has an audience under it.
                ->push($this->googleSearch([['adGroup' => ['id' => '1']]])),
        ]);

        try {
            $this->googleAdapter()->createAdSet(
                $this->account(),
                $this->adSetDraft(bid: BidStrategy::BidCap),
                'key-1',
            );
            $this->fail('The ad set should have been refused.');
        } catch (ProviderUnavailable $exception) {
            // Google bids per campaign. Flipping it because a second audience
            // was added would change how the first one spends.
            $this->assertStringContainsString('whole campaign', $exception->clientMessage);
        }
    }

    // ------------------------------------------------------------------
    // Ads
    // ------------------------------------------------------------------

    #[Test]
    public function an_ad_without_enough_copy_is_refused_rather_than_having_copy_invented(): void
    {
        $this->fakeGoogle(['*googleAds:search*' => Http::response($this->googleSearch([]))]);

        try {
            $this->googleAdapter()->createAd($this->account(), $this->adDraft(), 'key-1');
            $this->fail('The ad should have been refused.');
        } catch (ProviderUnavailable $exception) {
            /*
             * An ad is the client's own words. Repeating a headline to make up
             * the count, or cutting one to thirty characters, would put
             * sentences in their mouth that they never approved.
             */
            $this->assertStringContainsString('3 different headlines', $exception->clientMessage);
        }
    }

    #[Test]
    public function copy_over_googles_limit_is_left_out_rather_than_cut_short(): void
    {
        $this->fakeGoogle(['*googleAds:search*' => Http::response($this->googleSearch([]))]);

        try {
            $this->googleAdapter()->createAd(
                $this->account(),
                $this->adDraft(
                    extraHeadlines: ['Parts for every model', str_repeat('a', 45)],
                    extraDescriptions: [],
                ),
                'key-1',
            );
            $this->fail('The ad should have been refused.');
        } catch (ProviderUnavailable $exception) {
            // Two usable headlines, not three: the long one is excluded rather
            // than truncated mid-word.
            $this->assertStringContainsString('30 characters', $exception->clientMessage);
        }
    }

    #[Test]
    public function an_ad_with_enough_copy_is_created_paused_with_the_reference_in_its_name(): void
    {
        $this->fakeGoogle([
            '*googleAds:search*' => Http::response($this->googleSearch([])),
            '*adGroupAds:mutate*' => Http::response(
                $this->googleMutated('customers/1234567890/adGroupAds/777000222~888'),
            ),
        ]);

        $published = $this->googleAdapter()->createAd(
            $this->account(),
            $this->adDraft(
                extraHeadlines: ['Parts for every model', 'Genuine, guaranteed'],
                extraDescriptions: ['Thousands of parts in stock today.'],
            ),
            'key-1',
        );

        $create = $this->mutationBody('adGroupAds:mutate')['operations'][0]['create'];

        $this->assertSame('PAUSED', $create['status']);
        $this->assertStringContainsString('ads360:01JAD000000000000000000001', $create['ad']['name']);
        $this->assertCount(3, $create['ad']['responsiveSearchAd']['headlines']);
        $this->assertGreaterThanOrEqual(2, count($create['ad']['responsiveSearchAd']['descriptions']));
        $this->assertFalse($published->wasExisting);
    }

    #[Test]
    public function duplicate_copy_is_not_counted_twice_towards_googles_minimum(): void
    {
        $this->fakeGoogle(['*googleAds:search*' => Http::response($this->googleSearch([]))]);

        // Three headlines, but two of them are the same words.
        $this->expectException(ProviderUnavailable::class);

        $this->googleAdapter()->createAd(
            $this->account(),
            $this->adDraft(extraHeadlines: ['Genuine parts, fast', 'Genuine parts, fast']),
            'key-1',
        );
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    #[Test]
    public function pausing_and_resuming_use_an_update_mask(): void
    {
        $this->fakeGoogle(['*campaigns:mutate*' => Http::response($this->googleMutated(self::CAMPAIGN))]);

        $this->googleAdapter()->setCampaignActive($this->account(), self::CAMPAIGN, false, 'key-1');

        $operation = $this->mutationBody('campaigns:mutate')['operations'][0];

        $this->assertSame('PAUSED', $operation['update']['status']);
        $this->assertSame('status', $operation['updateMask']);
    }

    #[Test]
    public function stopping_a_campaign_removes_it_rather_than_pausing_it(): void
    {
        $this->fakeGoogle(['*campaigns:mutate*' => Http::response($this->googleMutated(self::CAMPAIGN))]);

        $this->googleAdapter()->stopCampaign($this->account(), self::CAMPAIGN, 'key-1');

        /*
         * Google's remove keeps the campaign queryable with its spend still
         * reporting, which is what lets it go on reconciling against the
         * client's ledger afterwards (§62).
         */
        $this->assertSame(
            self::CAMPAIGN,
            $this->mutationBody('campaigns:mutate')['operations'][0]['remove'] ?? null,
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function fakeAdGroupCreation(): void
    {
        $this->fakeGoogle([
            '*googleAds:search*' => Http::sequence()
                // No ad group for this reference yet.
                ->push($this->googleSearch([]))
                // The campaign it belongs to.
                ->push($this->googleSearch([['campaign' => [
                    'id' => '555000111',
                    'biddingStrategyType' => 'TARGET_SPEND',
                ]]]))
                // Bangladesh's location constant.
                ->push($this->googleSearch([['geoTargetConstant' => [
                    'id' => '2050',
                    'resourceName' => 'geoTargetConstants/2050',
                    'countryCode' => 'BD',
                ]]]))
                // No criteria on the campaign yet.
                ->push($this->googleSearch([])),
            '*campaignCriteria:mutate*' => Http::response(
                $this->googleMutated('customers/1234567890/campaignCriteria/555000111~1'),
            ),
            '*adGroups:mutate*' => Http::response($this->googleMutated(self::AD_GROUP)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mutationBody(string $needle): array
    {
        foreach ($this->recordedGoogleRequests() as $request) {
            if (str_contains($request->url(), $needle)) {
                return $request->data();
            }
        }

        $this->fail("No request was sent to [{$needle}].");
    }

    /**
     * @return array<string, mixed>
     */
    private function campaignCreatePayload(): array
    {
        return $this->mutationBody('campaigns:mutate')['operations'][0]['create'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetCreatePayload(): array
    {
        return $this->mutationBody('campaignBudgets:mutate')['operations'][0]['create'] ?? [];
    }
}
