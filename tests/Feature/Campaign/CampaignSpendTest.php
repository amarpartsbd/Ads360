<?php

declare(strict_types=1);

namespace Tests\Feature\Campaign;

use App\Domains\Advertising\DTOs\CampaignInsights;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Providers\MockAdvertisingProvider;
use App\Domains\Advertising\Services\ProviderManager;
use App\Domains\Campaign\Actions\ControlCampaign;
use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Campaign\Services\CampaignPublisher;
use App\Domains\Campaign\Services\CampaignSpendReconciler;
use App\Domains\Wallet\Enums\LedgerEntryType;
use App\Domains\Wallet\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsCampaigns;
use Tests\TestCase;

/**
 * Turning provider spend into ledger entries (spec §32, §78).
 *
 * The money assertions here are the ones that would cost a client real
 * currency if they were wrong.
 */
final class CampaignSpendTest extends TestCase
{
    use BuildsCampaigns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function spend_is_captured_with_its_fee_as_separate_entries(): void
    {
        $campaign = $this->publishedCampaign();

        $this->reconciler()->apply(
            $campaign->fresh(),
            new CampaignInsights($campaign->provider_campaign_id, spendMinor: 100_000, currency: 'BDT'),
        );

        $campaign->refresh();

        $spend = LedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('type', LedgerEntryType::CampaignSpend)
            ->sum('debit');

        $fee = LedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('type', LedgerEntryType::ManagementFee)
            ->sum('debit');

        // The advertising and the platform's cut are told apart, so a client's
        // statement does not claim we sent the provider more than we did.
        $this->assertSame(100_000, (int) $spend);
        $this->assertGreaterThan(0, (int) $fee);
        $this->assertSame((int) $spend + (int) $fee, $campaign->captured_amount);
    }

    #[Test]
    public function the_same_figure_reported_twice_is_charged_once(): void
    {
        $campaign = $this->publishedCampaign();
        $insights = new CampaignInsights($campaign->provider_campaign_id, spendMinor: 100_000, currency: 'BDT');

        $this->reconciler()->apply($campaign->fresh(), $insights);
        $first = $campaign->fresh()->captured_amount;

        $this->reconciler()->apply($campaign->fresh(), $insights);

        $this->assertSame($first, $campaign->fresh()->captured_amount);
    }

    #[Test]
    public function a_provider_that_reports_no_spend_changes_nothing(): void
    {
        $campaign = $this->publishedCampaign();

        $this->reconciler()->apply(
            $campaign->fresh(),
            new CampaignInsights($campaign->provider_campaign_id, spendMinor: 50_000, currency: 'BDT'),
        );

        $captured = $campaign->fresh()->captured_amount;

        // Null is "not reported", never "spent nothing" (spec §87).
        $this->reconciler()->apply(
            $campaign->fresh(),
            new CampaignInsights($campaign->provider_campaign_id, spendMinor: null),
        );

        $this->assertSame($captured, $campaign->fresh()->captured_amount);
    }

    #[Test]
    public function a_client_is_never_charged_beyond_the_budget_they_agreed_to(): void
    {
        $campaign = $this->publishedCampaign('1000.00');

        // The provider claims far more than the campaign's budget.
        $this->reconciler()->apply(
            $campaign->fresh(),
            new CampaignInsights($campaign->provider_campaign_id, spendMinor: 99_999_999, currency: 'BDT'),
        );

        $campaign->refresh();

        $this->assertSame($campaign->charged_total, $campaign->captured_amount);
        $this->assertLessThanOrEqual($campaign->charged_total, $campaign->captured_amount);
    }

    #[Test]
    public function completing_a_campaign_returns_the_unspent_hold(): void
    {
        $this->prepareCampaignWorkspace();

        // Read before approval, so it is the balance the whole campaign is
        // measured against rather than one already reduced by the hold.
        $funded = $this->campaignWallet()->fresh()->available_balance_cached;

        $campaign = $this->publishedCampaign('5000.00');
        $wallet = $this->campaignWallet();

        $this->reconciler()->apply(
            $campaign->fresh(),
            new CampaignInsights($campaign->provider_campaign_id, spendMinor: 100_000, currency: 'BDT'),
        );

        $this->reconciler()->complete($campaign->fresh());

        $campaign->refresh();
        $wallet->refresh();

        $this->assertSame(CampaignStatus::Completed, $campaign->status);
        $this->assertSame(0, $wallet->reserved_balance_cached);
        // The client is out exactly what was spent, and not a unit more.
        $this->assertSame($funded - $campaign->captured_amount, $wallet->available_balance_cached);
        $this->assertTrue($wallet->isReconciled());
    }

    #[Test]
    public function completing_twice_does_not_return_the_money_twice(): void
    {
        $campaign = $this->publishedCampaign();

        $this->reconciler()->complete($campaign->fresh());
        $available = $this->campaignWallet()->fresh()->available_balance_cached;

        $this->reconciler()->complete($campaign->fresh());

        $this->assertSame($available, $this->campaignWallet()->fresh()->available_balance_cached);
    }

    #[Test]
    public function an_accounts_headroom_is_released_as_a_campaign_spends(): void
    {
        $campaign = $this->publishedCampaign('5000.00');
        $account = AdAccount::query()->findOrFail($campaign->ad_account_id);

        $this->assertSame($campaign->budget_amount, $account->committed_amount);

        $this->reconciler()->apply(
            $campaign->fresh(),
            new CampaignInsights($campaign->provider_campaign_id, spendMinor: 200_000, currency: 'BDT'),
            $account->fresh(),
        );

        // Committed falls by what has been spent, not by the whole budget.
        $this->assertSame(300_000, $account->fresh()->committed_amount);
    }

    #[Test]
    public function repeated_syncs_do_not_release_the_same_headroom_twice(): void
    {
        $campaign = $this->publishedCampaign('5000.00');
        $account = AdAccount::query()->findOrFail($campaign->ad_account_id);

        $insights = new CampaignInsights($campaign->provider_campaign_id, spendMinor: 200_000, currency: 'BDT');

        $this->reconciler()->apply($campaign->fresh(), $insights, $account->fresh());
        $this->reconciler()->apply($campaign->fresh(), $insights, $account->fresh());
        $this->reconciler()->apply($campaign->fresh(), $insights, $account->fresh());

        $this->assertSame(300_000, $account->fresh()->committed_amount);
    }

    #[Test]
    public function completing_frees_the_accounts_remaining_headroom(): void
    {
        $campaign = $this->publishedCampaign('5000.00');
        $account = AdAccount::query()->findOrFail($campaign->ad_account_id);

        $this->reconciler()->complete($campaign->fresh());

        $this->assertSame(0, $account->fresh()->committed_amount);
    }

    #[Test]
    public function pausing_tells_the_provider_before_changing_the_status(): void
    {
        $campaign = $this->publishedCampaign();

        app(ControlCampaign::class)->pause($campaign->fresh(), $this->reviewer());

        $campaign->refresh();

        $this->assertSame(CampaignStatus::Paused, $campaign->status);
        $this->assertSame('PAUSED', $this->provider()->campaignState((string) $campaign->provider_campaign_id));
    }

    #[Test]
    public function a_pause_the_provider_refuses_leaves_the_status_alone(): void
    {
        $campaign = $this->publishedCampaign();

        $this->provider()->willFail(
            'setCampaignActive',
            \App\Domains\Advertising\Exceptions\ProviderUnavailable::transient(Provider::Meta, 'timeout'),
        );

        try {
            app(ControlCampaign::class)->pause($campaign->fresh(), $this->reviewer());
            $this->fail('The pause should have failed.');
        } catch (\App\Domains\Advertising\Exceptions\ProviderUnavailable) {
            // Expected.
        }

        // Showing a paused campaign that is still spending is the worst lie
        // this screen could tell, so the status only moves on success.
        $this->assertSame(CampaignStatus::Active, $campaign->fresh()->status);
    }

    #[Test]
    public function pausing_does_not_give_the_budget_back(): void
    {
        $campaign = $this->publishedCampaign();
        $reserved = $this->campaignWallet()->fresh()->reserved_balance_cached;

        app(ControlCampaign::class)->pause($campaign->fresh(), $this->reviewer());

        // A paused campaign is expected to resume; the money has to still be
        // there when it does.
        $this->assertSame($reserved, $this->campaignWallet()->fresh()->reserved_balance_cached);
    }

    #[Test]
    public function stopping_settles_the_money_and_finishes_the_campaign(): void
    {
        $campaign = $this->publishedCampaign();

        app(ControlCampaign::class)->stop($campaign->fresh(), $this->reviewer(), 'Client asked to stop');

        $campaign->refresh();

        $this->assertSame(CampaignStatus::Completed, $campaign->status);
        $this->assertSame(0, $this->campaignWallet()->fresh()->reserved_balance_cached);
    }

    private function publishedCampaign(string $budget = '5000.00'): Campaign
    {
        $campaign = $this->approvedCampaign($budget);

        app(CampaignPublisher::class)->publish($campaign->fresh());

        return $campaign->fresh();
    }

    private function reconciler(): CampaignSpendReconciler
    {
        return app(CampaignSpendReconciler::class);
    }

    private function provider(): MockAdvertisingProvider
    {
        $adapter = app(ProviderManager::class)->for(Provider::Meta);

        $this->assertInstanceOf(MockAdvertisingProvider::class, $adapter);

        return $adapter;
    }
}
