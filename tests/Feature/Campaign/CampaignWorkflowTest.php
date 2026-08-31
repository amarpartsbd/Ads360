<?php

declare(strict_types=1);

namespace Tests\Feature\Campaign;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Campaign\Actions\ApproveCampaign;
use App\Domains\Campaign\Actions\RejectCampaign;
use App\Domains\Campaign\Actions\SaveCampaign;
use App\Domains\Campaign\Actions\SubmitCampaign;
use App\Domains\Campaign\Enums\BudgetType;
use App\Domains\Campaign\Enums\CampaignObjective;
use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Exceptions\CampaignException;
use App\Domains\Campaign\Exceptions\IncompleteCampaign;
use App\Domains\Campaign\Jobs\PublishCampaign;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Campaign\Services\CampaignCosting;
use App\Support\Values\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsCampaigns;
use Tests\TestCase;

/**
 * The campaign lifecycle from draft to approval (spec §21, §22, §25).
 */
final class CampaignWorkflowTest extends TestCase
{
    use BuildsCampaigns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function a_budget_is_converted_from_a_decimal_by_the_server(): void
    {
        $this->prepareCampaignWorkspace();

        $campaign = app(SaveCampaign::class)->create(
            organization: $this->campaignOrganization(),
            name: 'Decimal test',
            provider: Provider::Meta,
            objective: CampaignObjective::Traffic,
            budgetType: BudgetType::Lifetime,
            budgetAmount: '1234.56',
            actor: $this->client(),
            startsAt: now()->addDay()->toDateTimeString(),
        );

        $this->assertSame(123_456, $campaign->budget_amount);
    }

    #[Test]
    public function an_incomplete_campaign_reports_every_problem_at_once(): void
    {
        $this->prepareCampaignWorkspace();

        $campaign = app(SaveCampaign::class)->create(
            organization: $this->campaignOrganization(),
            name: 'Empty',
            provider: Provider::Meta,
            objective: CampaignObjective::Traffic,
            budgetType: BudgetType::Daily,
            budgetAmount: '100.00',
            actor: $this->client(),
            startsAt: now()->addDay()->toDateTimeString(),
        );

        try {
            app(SubmitCampaign::class)->handle($campaign, $this->client());
            $this->fail('An incomplete campaign should not submit.');
        } catch (IncompleteCampaign $exception) {
            // A daily budget with no end date, and no audience: both, not one.
            $this->assertGreaterThan(1, count($exception->reasons));
        }
    }

    #[Test]
    public function submission_freezes_the_price(): void
    {
        $campaign = $this->submittedCampaign('5000.00');

        $this->assertSame(CampaignStatus::PendingReview, $campaign->status);
        $this->assertGreaterThan(0, $campaign->charged_total);
        $this->assertSame(
            $campaign->budget_amount + $campaign->fee_total,
            $campaign->charged_total,
        );
        $this->assertNotSame([], $campaign->pricing_snapshot);
    }

    #[Test]
    public function a_price_that_changes_after_submission_does_not_change_the_charge(): void
    {
        $campaign = $this->submittedCampaign('5000.00');
        $frozen = $campaign->charged_total;

        // Every percentage fee triples between submission and approval.
        \App\Domains\Billing\Models\PricingRule::query()
            ->whereNotNull('percentage')
            ->update(['percentage' => '30.0000']);

        app(ApproveCampaign::class)->handle($campaign->fresh(), $this->reviewer());

        $this->assertSame($frozen, $campaign->fresh()->charged_total);
        $this->assertSame(
            $frozen,
            $campaign->fresh()->reservation()->withoutGlobalScopes()->first()?->amount,
        );
    }

    #[Test]
    public function a_campaign_cannot_be_edited_once_it_is_in_review(): void
    {
        $campaign = $this->submittedCampaign();

        $this->expectException(CampaignException::class);

        app(SaveCampaign::class)->update($campaign, ['name' => 'Changed'], $this->client());
    }

    #[Test]
    public function approval_holds_the_budget_and_allocates_an_account(): void
    {
        // The workspace has to exist before its wallet can be read; funding
        // happens as part of preparing it.
        $this->prepareCampaignWorkspace();

        $wallet = $this->campaignWallet();
        $before = $wallet->fresh()->available_balance_cached;

        $campaign = $this->approvedCampaign('5000.00');
        $wallet->refresh();

        $this->assertSame(CampaignStatus::Approved, $campaign->status);
        $this->assertNotNull($campaign->wallet_reservation_id);
        $this->assertNotNull($campaign->ad_account_id);

        // Held, not spent: available falls by exactly the charged total and
        // reserved rises by the same.
        $this->assertSame($before - $campaign->charged_total, $wallet->available_balance_cached);
        $this->assertSame($campaign->charged_total, $wallet->reserved_balance_cached);

        // The account is committed to the advertising budget, not the fees —
        // those are ours, not the provider's.
        $this->assertSame(
            $campaign->budget_amount,
            AdAccount::query()->findOrFail($campaign->ad_account_id)->committed_amount,
        );
    }

    #[Test]
    public function approval_queues_publishing_rather_than_doing_it_inline(): void
    {
        $this->approvedCampaign();

        Queue::assertPushed(PublishCampaign::class);
    }

    #[Test]
    public function a_campaign_the_wallet_cannot_cover_is_refused_before_submission(): void
    {
        // Funded with far less than the campaign will cost.
        $this->prepareCampaignWorkspace(funding: '10.00');

        $campaign = $this->draftCampaign('5000.00');

        $this->expectException(IncompleteCampaign::class);

        app(SubmitCampaign::class)->handle($campaign->fresh(), $this->client());
    }

    #[Test]
    public function a_reviewer_cannot_approve_their_own_submission(): void
    {
        $this->prepareCampaignWorkspace();
        $campaign = $this->draftCampaign();

        // The same person submits and then tries to approve.
        $submitter = $this->client();
        app(SubmitCampaign::class)->handle($campaign->fresh(), $submitter);

        $this->expectException(CampaignException::class);

        app(ApproveCampaign::class)->handle($campaign->fresh(), $submitter);
    }

    #[Test]
    public function rejecting_holds_no_money(): void
    {
        $campaign = $this->submittedCampaign();
        $wallet = $this->campaignWallet();
        $before = $wallet->fresh()->available_balance_cached;

        app(RejectCampaign::class)->reject(
            $campaign->fresh(),
            $this->reviewer(),
            'The landing page does not match the advertised offer.',
        );

        $campaign->refresh();
        $wallet->refresh();

        $this->assertSame(CampaignStatus::Rejected, $campaign->status);
        $this->assertNull($campaign->wallet_reservation_id);
        // Nothing was ever held, so nothing had to be given back.
        $this->assertSame($before, $wallet->available_balance_cached);
        $this->assertSame(0, $wallet->reserved_balance_cached);
    }

    #[Test]
    public function asking_for_changes_returns_the_campaign_to_the_client(): void
    {
        $campaign = $this->submittedCampaign();

        app(RejectCampaign::class)->requestChanges(
            $campaign->fresh(),
            $this->reviewer(),
            'Please add a clearer call to action.',
        );

        $campaign->refresh();

        $this->assertSame(CampaignStatus::ChangesRequested, $campaign->status);
        $this->assertTrue($campaign->isEditable());
        $this->assertNotNull($campaign->review_notes);
    }

    #[Test]
    public function a_rejected_campaign_cannot_be_revived(): void
    {
        $campaign = $this->submittedCampaign();

        app(RejectCampaign::class)->reject($campaign->fresh(), $this->reviewer(), 'Not suitable at all.');

        $this->assertFalse(
            $campaign->fresh()->status->canTransitionTo(CampaignStatus::PendingReview),
        );
    }

    #[Test]
    public function a_daily_budget_commits_the_whole_run_not_one_day(): void
    {
        $this->prepareCampaignWorkspace();

        $campaign = app(SaveCampaign::class)->create(
            organization: $this->campaignOrganization(),
            name: 'Daily',
            provider: Provider::Meta,
            objective: CampaignObjective::Traffic,
            budgetType: BudgetType::Daily,
            budgetAmount: '100.00',
            actor: $this->client(),
            startsAt: now()->addDay()->toDateTimeString(),
            endsAt: now()->addDays(11)->toDateTimeString(),
        );

        // Ten days at 100.00 is what the client is committing to, and what the
        // wallet has to be able to cover.
        $this->assertSame(100_000, $campaign->committedBudget()->minorUnits);
        $this->assertTrue(
            app(CampaignCosting::class)->total($campaign)->greaterThan(Money::of('1000.00', 'BDT')),
        );
    }

    #[Test]
    public function the_approval_is_audited_with_the_amount_it_committed(): void
    {
        $campaign = $this->approvedCampaign();

        $entry = AuditLog::query()->where('action', 'campaign.approved')->firstOrFail();

        $this->assertSame($campaign->currency, $entry->context['currency']);
        $this->assertNotEmpty($entry->context['charged_total']);
    }

    #[Test]
    public function an_objective_the_provider_does_not_offer_is_refused(): void
    {
        $this->prepareCampaignWorkspace();

        $this->expectException(CampaignException::class);

        app(SaveCampaign::class)->create(
            organization: $this->campaignOrganization(),
            name: 'Unsupported',
            provider: Provider::Google,
            objective: CampaignObjective::Engagement,
            budgetType: BudgetType::Lifetime,
            budgetAmount: '100.00',
            actor: $this->client(),
            startsAt: now()->addDay()->toDateTimeString(),
        );
    }
}
