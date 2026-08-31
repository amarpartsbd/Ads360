<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domains\Advertising\DTOs\CampaignInsights;
use App\Domains\Analytics\Actions\ResolveReconciliation;
use App\Domains\Analytics\Enums\ReconciliationStatus;
use App\Domains\Analytics\Models\CampaignDailyMetric;
use App\Domains\Analytics\Models\SpendReconciliation;
use App\Domains\Analytics\Services\SpendReconciler;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Campaign\Services\CampaignPublisher;
use App\Domains\Campaign\Services\CampaignSpendReconciler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\BuildsCampaigns;
use Tests\TestCase;

/**
 * Provider spend against the ledger (spec §78, §25).
 *
 * The platform keeps two independent accounts of the same money. These tests
 * are about what happens when they disagree — and, crucially, about what does
 * *not* happen: no balance moves here.
 */
final class SpendReconciliationTest extends TestCase
{
    use BuildsCampaigns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        config()->set('platform.advertising.reconciliation.tolerance_minor', 10000);
        config()->set('platform.advertising.reconciliation.tolerance_percent', 2);
    }

    #[Test]
    public function agreement_is_recorded_as_balanced_not_left_unrecorded(): void
    {
        $campaign = $this->campaignWithSpend(providerSpend: 100_000, capturedSpend: 100_000);

        $result = app(SpendReconciler::class)->reconcile($campaign);

        // "We checked and they agreed" is the evidence an auditor asks for; a
        // table holding only problems cannot tell a clean month from an
        // unchecked one.
        $this->assertNotNull($result);
        $this->assertSame(ReconciliationStatus::Balanced, $result->status);
        $this->assertSame(0, $result->variance);
    }

    #[Test]
    public function a_small_difference_is_within_tolerance(): void
    {
        // The last sync before the check was minutes old; that lag is normal.
        $campaign = $this->campaignWithSpend(providerSpend: 105_000, capturedSpend: 100_000);

        $result = app(SpendReconciler::class)->reconcile($campaign);

        $this->assertSame(ReconciliationStatus::Balanced, $result?->status);
    }

    #[Test]
    public function a_large_difference_is_raised_for_investigation(): void
    {
        $campaign = $this->campaignWithSpend(providerSpend: 400_000, capturedSpend: 100_000);

        $result = app(SpendReconciler::class)->reconcile($campaign);

        $this->assertSame(ReconciliationStatus::Investigating, $result?->status);
        $this->assertSame(300_000, $result->variance);
        // Positive means the provider says more was spent than the platform
        // charged for — the direction that costs the platform money.
        $this->assertTrue($result->underCharged());
    }

    #[Test]
    public function a_small_campaign_is_not_excused_by_the_percentage_alone(): void
    {
        // Both tests have to pass: 15,000 minor is over the absolute floor,
        // and on a 20,000 campaign it is far over the percentage too.
        $campaign = $this->campaignWithSpend(providerSpend: 35_000, capturedSpend: 20_000);

        $this->assertSame(
            ReconciliationStatus::Investigating,
            app(SpendReconciler::class)->reconcile($campaign)?->status,
        );
    }

    #[Test]
    public function a_large_campaign_is_not_flagged_for_ordinary_lag(): void
    {
        // 100,000 on a 10,000,000 campaign is 1% — under the percentage, even
        // though it is well over the absolute floor. The budget has to be big
        // enough to hold the spend: capture is capped at what was agreed.
        $campaign = $this->campaignWithSpend(
            providerSpend: 10_100_000,
            capturedSpend: 10_000_000,
            budget: '150000.00',
        );

        $this->assertSame(
            ReconciliationStatus::Balanced,
            app(SpendReconciler::class)->reconcile($campaign)?->status,
        );
    }

    #[Test]
    public function money_captured_with_no_reported_spend_is_always_raised(): void
    {
        $campaign = $this->campaignWithSpend(providerSpend: 0, capturedSpend: 50_000);

        $result = app(SpendReconciler::class)->reconcile($campaign);

        $this->assertSame(ReconciliationStatus::Investigating, $result?->status);
        $this->assertLessThan(0, $result->variance);
    }

    #[Test]
    public function reconciliation_moves_no_money(): void
    {
        $campaign = $this->campaignWithSpend(providerSpend: 900_000, capturedSpend: 100_000);
        $wallet = $this->campaignWallet();

        $before = [
            'available' => $wallet->fresh()->available_balance_cached,
            'reserved' => $wallet->fresh()->reserved_balance_cached,
        ];

        app(SpendReconciler::class)->reconcile($campaign);

        $wallet->refresh();

        // A scheduled job with unattended write access to client funds is
        // exactly what maker-checker exists to prevent (§25).
        $this->assertSame($before['available'], $wallet->available_balance_cached);
        $this->assertSame($before['reserved'], $wallet->reserved_balance_cached);
    }

    #[Test]
    public function re_running_updates_the_same_row_rather_than_queueing_copies(): void
    {
        $campaign = $this->campaignWithSpend(providerSpend: 400_000, capturedSpend: 100_000);

        app(SpendReconciler::class)->reconcile($campaign);
        app(SpendReconciler::class)->reconcile($campaign);
        app(SpendReconciler::class)->reconcile($campaign);

        $this->assertSame(1, SpendReconciliation::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_discrepancy_someone_settled_is_not_reopened_by_a_later_run(): void
    {
        $campaign = $this->campaignWithSpend(providerSpend: 400_000, capturedSpend: 100_000);

        $reconciliation = app(SpendReconciler::class)->reconcile($campaign);

        app(ResolveReconciliation::class)->handle(
            $reconciliation,
            $this->reviewer(),
            'Provider restated a month-old day; adjustment raised separately.',
        );

        app(SpendReconciler::class)->reconcile($campaign);

        // Putting it back in the queue every hour after a human decided about
        // it would make the queue useless.
        $this->assertSame(
            ReconciliationStatus::Resolved,
            $reconciliation->fresh()->status,
        );
    }

    #[Test]
    public function settling_without_a_reason_is_refused(): void
    {
        $campaign = $this->campaignWithSpend(providerSpend: 400_000, capturedSpend: 100_000);
        $reconciliation = app(SpendReconciler::class)->reconcile($campaign);

        $this->expectException(RuntimeException::class);

        app(ResolveReconciliation::class)->handle($reconciliation, $this->reviewer(), '   ');
    }

    #[Test]
    public function a_discrepancy_is_recorded_in_the_audit_log(): void
    {
        $campaign = $this->campaignWithSpend(providerSpend: 400_000, capturedSpend: 100_000);

        app(SpendReconciler::class)->reconcile($campaign);

        $entry = AuditLog::query()
            ->where('action', 'analytics.reconciliation.discrepancy')
            ->firstOrFail();

        $this->assertSame($campaign->public_id, $entry->context['campaign']);
    }

    #[Test]
    public function the_database_refuses_a_variance_that_does_not_follow_from_the_figures(): void
    {
        $campaign = $this->campaignWithSpend(providerSpend: 100_000, capturedSpend: 100_000);
        $row = app(SpendReconciler::class)->reconcile($campaign);

        $this->expectException(QueryException::class);

        // A row whose variance disagreed with the two figures beside it would
        // make the whole table untrustworthy.
        DB::table('spend_reconciliations')
            ->where('id', $row->getKey())
            ->update(['variance' => 999]);
    }

    #[Test]
    public function the_database_refuses_a_resolved_row_with_no_explanation(): void
    {
        $campaign = $this->campaignWithSpend(providerSpend: 400_000, capturedSpend: 100_000);
        $row = app(SpendReconciler::class)->reconcile($campaign);

        $this->expectException(QueryException::class);

        DB::table('spend_reconciliations')
            ->where('id', $row->getKey())
            ->update(['status' => 'RESOLVED', 'resolved_at' => null, 'resolution_note' => null]);
    }

    /**
     * A published campaign where the provider reports one figure and the
     * ledger has captured another.
     */
    private function campaignWithSpend(
        int $providerSpend,
        int $capturedSpend,
        string $budget = '20000.00',
    ): Campaign {
        $campaign = $this->approvedCampaign($budget);

        app(CampaignPublisher::class)->publish($campaign->fresh());

        /*
         * Submission requires a start date in the future, so the fixture winds
         * it back afterwards: reconciliation is about campaigns that have
         * actually run, and one starting tomorrow has nothing to compare.
         */
        $campaign->forceFill(['starts_at' => now()->subDays(3)])->save();
        $campaign->refresh();

        if ($capturedSpend > 0) {
            // Goes through the real capture path, so the ledger entries are
            // the ones reconciliation actually reads.
            app(CampaignSpendReconciler::class)->apply(
                $campaign,
                new CampaignInsights($campaign->provider_campaign_id, spendMinor: $capturedSpend, currency: 'BDT'),
            );

            $campaign->refresh();
        }

        if ($providerSpend > 0) {
            CampaignDailyMetric::factory()
                ->forCampaign($campaign)
                ->on(now()->subDay())
                ->spent($providerSpend)
                ->create();
        }

        return $campaign;
    }
}
