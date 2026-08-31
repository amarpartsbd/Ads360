<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising\Meta;

use App\Domains\Advertising\DTOs\AdDraft;
use App\Domains\Advertising\DTOs\AdSetDraft;
use App\Domains\Advertising\DTOs\CampaignDraft;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Campaign\Enums\BidStrategy;
use App\Domains\Campaign\Enums\BudgetType;
use App\Domains\Campaign\Enums\CampaignObjective;
use App\Domains\Campaign\Values\Targeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesMetaGraph;
use Tests\TestCase;

/**
 * Publishing to Meta (spec §21, §26, §27, Rule 17).
 *
 * The idempotency tests here are the important ones. Meta has no
 * idempotency-key header, so the guarantee is earned by a pre-flight lookup —
 * and if that lookup is wrong, a client gets charged twice.
 */
final class MetaPublishingTest extends TestCase
{
    use FakesMetaGraph;
    use RefreshDatabase;

    private ?AdAccount $account = null;

    /**
     * Memoised: the inventory refuses two rows for one provider account, and
     * a test wanting the account twice means the same account both times.
     */
    private function account(): AdAccount
    {
        return $this->account ??= AdAccount::factory()->create([
            'external_account_id' => 'act_112233',
            'currency' => 'BDT',
        ]);
    }

    private function campaignDraft(string $reference = '01JCAMPAIGN0000000000000001'): CampaignDraft
    {
        return new CampaignDraft(
            reference: $reference,
            name: 'Eid Collection',
            objective: CampaignObjective::Traffic,
            budgetType: BudgetType::Lifetime,
            budgetMinor: 2_500_000,
            currency: 'BDT',
        );
    }

    #[Test]
    public function a_campaign_is_created_paused_so_it_cannot_spend_before_its_ads_exist(): void
    {
        Http::fake([
            '*campaigns*' => Http::sequence()
                ->push(['data' => []])
                ->push(['id' => '23850000000000001']),
        ]);

        $this->metaAdapter()->createCampaign($this->account(), $this->campaignDraft(), 'key-1');

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST') {
                return false;
            }

            // A campaign that went live on creation would start spending on a
            // structure that is not yet what was approved.
            return ($request->data()['status'] ?? null) === 'PAUSED';
        });
    }

    #[Test]
    public function the_platform_reference_is_carried_in_the_name_meta_stores(): void
    {
        Http::fake([
            '*campaigns*' => Http::sequence()
                ->push(['data' => []])
                ->push(['id' => '23850000000000001']),
        ]);

        $this->metaAdapter()->createCampaign($this->account(), $this->campaignDraft(), 'key-1');

        Http::assertSent(function (Request $request): bool {
            $name = $request->data()['name'] ?? '';

            return $request->method() === 'POST'
                && str_contains($name, 'Eid Collection')
                // This is what a later attempt looks for.
                && str_contains($name, 'ads360:01JCAMPAIGN0000000000000001');
        });
    }

    #[Test]
    public function a_second_attempt_finds_the_existing_campaign_instead_of_creating_another(): void
    {
        Http::fake([
            // The lookup finds an object already carrying the reference.
            '*campaigns*' => Http::response([
                'data' => [[
                    'id' => '23850000000000001',
                    'name' => 'Eid Collection [ads360:01JCAMPAIGN0000000000000001]',
                    'status' => 'PAUSED',
                ]],
            ]),
        ]);

        $result = $this->metaAdapter()->createCampaign($this->account(), $this->campaignDraft(), 'key-2');

        $this->assertSame('23850000000000001', $result->externalId);
        // The signal the publication ledger uses to know this was not a fresh
        // creation.
        $this->assertTrue($result->wasExisting);

        // Nothing was posted: no second campaign, no second budget.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
        $this->assertCount(
            0,
            array_filter($this->recordedRequests(), static fn (Request $r): bool => $r->method() === 'POST'),
        );
    }

    #[Test]
    public function a_different_campaigns_reference_is_not_mistaken_for_this_one(): void
    {
        Http::fake([
            '*campaigns*' => Http::sequence()
                // Another campaign entirely, from the same account.
                ->push(['data' => [[
                    'id' => '23850000000000999',
                    'name' => 'Winter Range [ads360:01JCAMPAIGN0000000000000002]',
                ]]])
                ->push(['id' => '23850000000000001']),
        ]);

        $result = $this->metaAdapter()->createCampaign($this->account(), $this->campaignDraft(), 'key-3');

        $this->assertFalse($result->wasExisting);
        $this->assertSame('23850000000000001', $result->externalId);
    }

    #[Test]
    public function a_long_campaign_name_is_truncated_but_the_reference_is_never_cut(): void
    {
        Http::fake([
            '*campaigns*' => Http::sequence()
                ->push(['data' => []])
                ->push(['id' => '23850000000000001']),
        ]);

        $draft = new CampaignDraft(
            reference: '01JCAMPAIGN0000000000000001',
            name: str_repeat('A very long campaign name. ', 40),
            objective: CampaignObjective::Traffic,
            budgetType: BudgetType::Lifetime,
            budgetMinor: 100_000,
            currency: 'BDT',
        );

        $this->metaAdapter()->createCampaign($this->account(), $draft, 'key-4');

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST') {
                return false;
            }

            $name = (string) ($request->data()['name'] ?? '');

            // A truncated reference would match the wrong object on a retry.
            return str_ends_with($name, '[ads360:01JCAMPAIGN0000000000000001]')
                && strlen($name) <= 400;
        });
    }

    #[Test]
    public function a_lifetime_budget_and_a_daily_budget_use_different_fields(): void
    {
        Http::fake([
            '*campaigns*' => Http::sequence()
                ->push(['data' => []])
                ->push(['id' => '1'])
                ->push(['data' => []])
                ->push(['id' => '2']),
        ]);

        $this->metaAdapter()->createCampaign($this->account(), $this->campaignDraft(), 'key-5');

        $daily = new CampaignDraft(
            reference: '01JCAMPAIGN0000000000000009',
            name: 'Daily',
            objective: CampaignObjective::Traffic,
            budgetType: BudgetType::Daily,
            budgetMinor: 100_000,
            currency: 'BDT',
        );

        $this->metaAdapter()->createCampaign($this->account(), $daily, 'key-6');

        $posted = array_values(array_filter(
            $this->recordedRequests(),
            static fn (Request $r): bool => $r->method() === 'POST',
        ));

        $this->assertArrayHasKey('lifetime_budget', $posted[0]->data());
        $this->assertArrayHasKey('daily_budget', $posted[1]->data());
    }

    #[Test]
    public function platform_objectives_are_translated_into_metas_dialect(): void
    {
        Http::fake([
            '*campaigns*' => Http::sequence()
                ->push(['data' => []])
                ->push(['id' => '1']),
        ]);

        $draft = new CampaignDraft(
            reference: '01JCAMPAIGN0000000000000003',
            name: 'Sales push',
            objective: CampaignObjective::Sales,
            budgetType: BudgetType::Lifetime,
            budgetMinor: 100_000,
            currency: 'BDT',
        );

        $this->metaAdapter()->createCampaign($this->account(), $draft, 'key-7');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && ($request->data()['objective'] ?? null) === 'OUTCOME_SALES');
    }

    #[Test]
    public function targeting_is_sent_in_metas_shape_with_genders_encoded_as_numbers(): void
    {
        Http::fake([
            '*adsets*' => Http::sequence()
                ->push(['data' => []])
                ->push(['id' => '23850000000000002']),
        ]);

        $draft = new AdSetDraft(
            reference: '01JADSET00000000000000001',
            externalCampaignId: '23850000000000001',
            name: 'Dhaka women 25-45',
            targeting: Targeting::fromArray([
                'countries' => ['BD'],
                'minimum_age' => 25,
                'maximum_age' => 45,
                'genders' => ['female'],
                'devices' => ['mobile'],
            ]),
            bidStrategy: BidStrategy::LowestCost,
        );

        $this->metaAdapter()->createAdSet($this->account(), $draft, 'key-8');

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST') {
                return false;
            }

            $targeting = json_decode((string) ($request->data()['targeting'] ?? '{}'), true);

            return $targeting['geo_locations']['countries'] === ['BD']
                && $targeting['age_min'] === 25
                && $targeting['age_max'] === 45
                // Meta encodes female as 2.
                && $targeting['genders'] === [2];
        });
    }

    #[Test]
    public function creating_an_ad_uploads_the_image_then_the_creative_then_the_ad(): void
    {
        Http::fake([
            '*ads*' => Http::sequence()
                ->push(['data' => []])
                ->push(['id' => '23850000000000003']),
            '*adimages*' => Http::response(['images' => ['file' => ['hash' => 'abc123hash']]]),
            '*adcreatives*' => Http::response(['id' => '23850000000000004']),
        ]);

        $result = $this->metaAdapter()->createAd($this->account(), $this->adDraft(), 'key-9');

        $this->assertSame('23850000000000003', $result->externalId);

        // The creative references the uploaded image by the hash Meta returned.
        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'adcreatives')) {
                return false;
            }

            return str_contains((string) ($request->data()['object_story_spec'] ?? ''), 'abc123hash');
        });
    }

    #[Test]
    public function an_ad_runs_under_the_clients_own_page(): void
    {
        Http::fake([
            '*ads*' => Http::sequence()->push(['data' => []])->push(['id' => '3']),
            '*adimages*' => Http::response(['images' => ['file' => ['hash' => 'abc123hash']]]),
            '*adcreatives*' => Http::response(['id' => '4']),
        ]);

        $this->metaAdapter()->createAd($this->account(), $this->adDraft(), 'key-10');

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'adcreatives')) {
                return false;
            }

            $spec = json_decode((string) ($request->data()['object_story_spec'] ?? '{}'), true);

            // The platform never lends its own identity to a client's ads.
            return ($spec['page_id'] ?? null) === '556677';
        });
    }

    #[Test]
    public function an_ad_with_no_readable_creative_is_refused_rather_than_published_blank(): void
    {
        Http::fake([
            '*ads*' => Http::response(['data' => []]),
        ]);

        $draft = new AdDraft(
            reference: '01JAD0000000000000000001',
            externalAdSetId: '23850000000000002',
            name: 'Broken ad',
            headline: 'Headline',
            primaryText: 'Body',
            destinationUrl: 'https://example.test',
            identityExternalId: '556677',
            creativeChecksum: 'deadbeef',
            openCreative: null,
        );

        try {
            $this->metaAdapter()->createAd($this->account(), $draft, 'key-11');
            $this->fail('The ad should have been refused.');
        } catch (ProviderUnavailable $exception) {
            $this->assertFalse($exception->retryable);
            $this->assertStringContainsString('image', $exception->clientMessage);
        }
    }

    #[Test]
    public function creative_enhancements_are_opted_out_so_approved_copy_stays_as_approved(): void
    {
        Http::fake([
            '*ads*' => Http::sequence()->push(['data' => []])->push(['id' => '3']),
            '*adimages*' => Http::response(['images' => ['file' => ['hash' => 'abc123hash']]]),
            '*adcreatives*' => Http::response(['id' => '4']),
        ]);

        $this->metaAdapter()->createAd($this->account(), $this->adDraft(), 'key-12');

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'adcreatives')) {
                return false;
            }

            // A reviewer approved specific copy and a specific image; Meta
            // restyling it afterwards would publish something nobody approved.
            return str_contains(
                (string) ($request->data()['degrees_of_freedom_spec'] ?? ''),
                'OPT_OUT',
            );
        });
    }

    #[Test]
    public function pausing_and_resuming_set_the_status_meta_expects(): void
    {
        Http::fake(['*' => Http::response(['success' => true])]);

        $this->metaAdapter()->setCampaignActive($this->account(), '23850000000000001', false, 'key-13');
        $this->metaAdapter()->setCampaignActive($this->account(), '23850000000000001', true, 'key-14');

        $posted = array_values(array_filter(
            $this->recordedRequests(),
            static fn (Request $r): bool => $r->method() === 'POST',
        ));

        $this->assertSame('PAUSED', $posted[0]->data()['status']);
        $this->assertSame('ACTIVE', $posted[1]->data()['status']);
    }

    #[Test]
    public function stopping_archives_rather_than_deletes(): void
    {
        Http::fake(['*' => Http::response(['success' => true])]);

        $this->metaAdapter()->stopCampaign($this->account(), '23850000000000001', 'key-15');

        // Deleting would take the spend history with it, and that history has
        // to reconcile against the client's ledger.
        Http::assertSent(fn (Request $request): bool => ($request->data()['status'] ?? null) === 'ARCHIVED');
    }

    #[Test]
    public function insights_convert_metas_decimal_spend_into_minor_units(): void
    {
        Http::fake([
            '*insights*' => Http::response([
                'data' => [[
                    'spend' => '1234.56',
                    'impressions' => '45000',
                    'clicks' => '890',
                ]],
            ]),
            '*' => Http::response(['status' => 'ACTIVE', 'effective_status' => 'ACTIVE']),
        ]);

        $insights = $this->metaAdapter()->campaignInsights($this->account(), '23850000000000001');

        // 1234.56 BDT is 123456 poisha.
        $this->assertSame(123_456, $insights->spendMinor);
        $this->assertSame(45_000, $insights->impressions);
        $this->assertSame(890, $insights->clicks);
    }

    #[Test]
    public function a_campaign_that_has_not_spent_yet_reports_nothing_rather_than_zero(): void
    {
        Http::fake([
            '*insights*' => Http::response(['data' => []]),
            '*' => Http::response(['status' => 'ACTIVE', 'effective_status' => 'ACTIVE']),
        ]);

        $insights = $this->metaAdapter()->campaignInsights($this->account(), '23850000000000001');

        // Zero would let the reconciler release a hold on a campaign that has
        // only just started.
        $this->assertNull($insights->spendMinor);
        $this->assertFalse($insights->reportsSpend());
    }

    #[Test]
    public function the_effective_status_is_preferred_over_the_bare_one(): void
    {
        Http::fake([
            '*insights*' => Http::response(['data' => []]),
            // Meta says the campaign is active, but nothing under it can run.
            '*' => Http::response(['status' => 'ACTIVE', 'effective_status' => 'CAMPAIGN_PAUSED']),
        ]);

        $insights = $this->metaAdapter()->campaignInsights($this->account(), '23850000000000001');

        $this->assertSame('CAMPAIGN_PAUSED', $insights->status);
    }

    private function adDraft(): AdDraft
    {
        return new AdDraft(
            reference: '01JAD0000000000000000001',
            externalAdSetId: '23850000000000002',
            name: 'Primary ad',
            headline: 'New season',
            primaryText: 'Free delivery over 2,000 taka.',
            destinationUrl: 'https://example.test/collection',
            identityExternalId: '556677',
            creativeChecksum: 'deadbeef',
            openCreative: static fn () => fopen('php://memory', 'rb+'),
            callToAction: 'SHOP_NOW',
        );
    }
}
