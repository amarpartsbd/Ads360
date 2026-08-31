<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\Enums\ReconciliationStatus;
use App\Domains\Analytics\Models\CampaignDailyMetric;
use App\Domains\Analytics\Models\SpendReconciliation;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Wallet\Enums\LedgerEntryType;
use App\Domains\Wallet\Models\LedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Compares what a provider says was spent against what the ledger captured
 * (spec §78).
 *
 * The platform keeps two independent accounts of the same money. One comes
 * from the provider, through the metrics pipeline. The other is what was
 * actually drawn from a client's wallet. They should agree, and when they do
 * not, something has gone wrong that nobody would otherwise notice: a sync
 * that quietly stopped, a provider restating a month-old day, a capture that
 * failed and was never retried.
 *
 * **This class never moves money.** It writes a row and, past a tolerance,
 * raises it for a person. Adjusting a client's balance is a maker-checker
 * action (§25); a scheduled job with unattended write access to client funds
 * is exactly what that control exists to prevent.
 *
 * A balanced result is recorded too. "We checked and they agreed" is the
 * evidence an auditor asks for, and a table holding only problems cannot
 * distinguish a clean month from a month nobody checked.
 */
final class SpendReconciler
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Compare one campaign over its whole life so far.
     *
     * Lifetime rather than a window, because that is the comparison that
     * actually has to hold: a restatement moves spend between days without
     * changing the total, and a per-day check would raise a discrepancy for
     * something that is not one.
     */
    public function reconcile(Campaign $campaign, ?Carbon $asOf = null): ?SpendReconciliation
    {
        if ($campaign->provider_campaign_id === null || $campaign->starts_at === null) {
            return null;
        }

        $asOf ??= Carbon::now();

        $periodStart = Carbon::instance($campaign->starts_at->toDateTime())->startOfDay();
        $periodEnd = $asOf->copy()->startOfDay();

        if ($periodStart->greaterThan($periodEnd)) {
            // Scheduled but not started. Nothing to compare yet.
            return null;
        }

        $providerSpend = $this->providerSpend($campaign);
        $ledgerSpend = $this->ledgerSpend($campaign);
        $variance = $providerSpend - $ledgerSpend;

        $status = $this->withinTolerance($variance, $providerSpend)
            ? ReconciliationStatus::Balanced
            : ReconciliationStatus::Investigating;

        $reconciliation = DB::transaction(fn (): SpendReconciliation => $this->write(
            $campaign,
            $periodStart,
            $periodEnd,
            $providerSpend,
            $ledgerSpend,
            $variance,
            $status,
            $asOf,
        ));

        if ($status->needsAttention()) {
            $this->raise($campaign, $reconciliation);
        }

        return $reconciliation;
    }

    /**
     * What the provider says, summed from the daily rows rather than from the
     * campaign's own `reported_spend`.
     *
     * Deliberately the independent number: `reported_spend` is written by the
     * same path that drives capture, so comparing it against the ledger would
     * largely be comparing a number with itself.
     */
    private function providerSpend(Campaign $campaign): int
    {
        return (int) CampaignDailyMetric::query()
            ->withoutGlobalScopes()
            ->where('campaign_id', $campaign->getKey())
            ->campaignLevel()
            ->sum('spend');
    }

    /**
     * What the client was actually charged for advertising, from the ledger.
     *
     * Fees are excluded on purpose: the provider never sees them, so including
     * them would show a variance on every campaign that had none.
     */
    private function ledgerSpend(Campaign $campaign): int
    {
        $reservationId = $campaign->wallet_reservation_id;

        if ($reservationId === null) {
            return 0;
        }

        return (int) LedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('reference_type', \App\Domains\Wallet\Models\WalletReservation::class)
            ->where('reference_id', (string) $reservationId)
            ->where('type', LedgerEntryType::CampaignSpend)
            ->sum('debit');
    }

    /**
     * Whether a difference is small enough to be the ordinary lag between two
     * systems rather than a fault.
     *
     * Both tests have to pass. An absolute floor alone would raise every large
     * campaign; a percentage alone would ignore a real problem on a small one.
     */
    private function withinTolerance(int $variance, int $providerSpend): bool
    {
        $absolute = abs($variance);

        $floor = (int) config('platform.advertising.reconciliation.tolerance_minor', 0);
        $percent = (int) config('platform.advertising.reconciliation.tolerance_percent', 0);

        if ($absolute <= $floor) {
            return true;
        }

        if ($providerSpend <= 0) {
            // No spend reported but money captured: never within tolerance,
            // whatever the percentage would say.
            return false;
        }

        // Integer comparison rather than a float ratio, so the boundary is
        // exact and does not move with rounding.
        return $absolute * 100 <= $providerSpend * $percent;
    }

    private function write(
        Campaign $campaign,
        Carbon $periodStart,
        Carbon $periodEnd,
        int $providerSpend,
        int $ledgerSpend,
        int $variance,
        ReconciliationStatus $status,
        Carbon $asOf,
    ): SpendReconciliation {
        /** @var SpendReconciliation|null $existing */
        $existing = SpendReconciliation::query()
            ->withoutGlobalScopes()
            ->where('campaign_id', $campaign->getKey())
            ->where('period_start', $periodStart->toDateString())
            ->where('period_end', $periodEnd->toDateString())
            ->first();

        $attributes = [
            'provider_spend' => $providerSpend,
            'ledger_spend' => $ledgerSpend,
            'variance' => $variance,
            'checked_at' => $asOf,
        ];

        if ($existing !== null) {
            /*
             * A discrepancy someone has already settled stays settled. Letting
             * a later run reopen it would put the same row back in the queue
             * every hour after a human had decided about it.
             */
            if ($existing->status !== ReconciliationStatus::Resolved) {
                $attributes['status'] = $status;
            }

            $existing->forceFill($attributes)->save();

            return $existing;
        }

        $reconciliation = new SpendReconciliation([
            'organization_id' => $campaign->organization_id,
            'campaign_id' => $campaign->getKey(),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'currency' => $campaign->currency,
            'status' => $status,
            ...$attributes,
        ]);

        $reconciliation->tenant_id = $campaign->tenant_id;
        $reconciliation->save();

        return $reconciliation;
    }

    private function raise(Campaign $campaign, SpendReconciliation $reconciliation): void
    {
        $this->audit->recordSystemEvent(
            action: AuditAction::SpendDiscrepancyFound,
            resource: $reconciliation,
            context: [
                'campaign' => $campaign->public_id,
                ...$reconciliation->describe(),
            ],
            label: 'SpendReconciler',
        );
    }
}
