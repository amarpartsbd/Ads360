<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Services;

use App\Domains\Advertising\DTOs\CampaignInsights;
use App\Domains\Advertising\Enums\ProviderCapability;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Services\ProviderManager;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Wallet\Enums\LedgerEntryType;
use App\Domains\Wallet\Models\WalletReservation;
use App\Domains\Wallet\Services\WalletService;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns what a provider says was spent into entries on the client's ledger
 * (spec §32, §78).
 *
 * Three decisions shape this class.
 *
 * **Cumulative, not incremental.** Providers report spend-to-date, and the
 * platform stores what it has captured to date. Every run captures the
 * difference. Adding up reported deltas instead would double-charge a client
 * the first time a sync ran twice, and lose money the first time one was
 * missed.
 *
 * **Fees follow spend.** A client agreed to fees on their budget; if they only
 * spend half of it, they owe fees on half. The fee due is recomputed from the
 * cumulative spend each time rather than accumulated, so rounding cannot drift
 * over a long campaign.
 *
 * **Silence is not zero.** A provider that does not report spend leaves the
 * stored figure alone (§87). Treating "not reported" as "spent nothing" would
 * release a client's held budget while their campaign was still running.
 */
final class CampaignSpendReconciler
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly WalletService $wallets,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Fetch and apply the provider's figures for one campaign.
     *
     * @throws ProviderUnavailable
     */
    public function sync(Campaign $campaign): Campaign
    {
        if ($campaign->provider_campaign_id === null) {
            return $campaign;
        }

        $account = $campaign->adAccount()->withoutGlobalScopes()->first();

        if ($account === null || ! $this->providers->isAvailable($campaign->provider)) {
            return $campaign;
        }

        $adapter = $this->providers->for($campaign->provider);

        if (! $adapter->supports(ProviderCapability::MetricsRetrieval)) {
            return $campaign;
        }

        $insights = $adapter->campaignInsights($account, $campaign->provider_campaign_id);

        return $this->apply($campaign, $insights, $account);
    }

    /**
     * Apply a set of figures. Public so a webhook can feed the same path a
     * scheduled sync uses — there is one place where provider spend becomes
     * ledger entries.
     */
    public function apply(Campaign $campaign, CampaignInsights $insights, ?AdAccount $account = null): Campaign
    {
        if (! $insights->reportsSpend()) {
            // Nothing said, nothing changed.
            return $campaign;
        }

        $reported = max(0, (int) $insights->spendMinor);

        // A provider cannot spend more than we asked it to, but its figures
        // can briefly exceed a budget while a final auction settles. The
        // client is never charged more than the budget they agreed to.
        $spendToDate = min($reported, $campaign->committedBudget()->minorUnits);

        $targetCapture = $spendToDate + $this->feeDueOn($campaign, $spendToDate);
        $delta = $targetCapture - $campaign->captured_amount;

        $campaign->reported_spend = $reported;

        if ($delta <= 0) {
            // Figures unchanged, or a provider correcting downward. Money
            // already captured is not given back here — a correction is a
            // reversal, made deliberately, not a side effect of a sync.
            $campaign->save();

            return $campaign;
        }

        $this->capture($campaign, $spendToDate, $delta);

        if ($account !== null) {
            $this->releaseAccountCommitment($account, $campaign, $spendToDate);
        }

        return $campaign;
    }

    /**
     * Finish a campaign: capture whatever is outstanding and give back the
     * rest of the hold (spec §32).
     */
    public function complete(Campaign $campaign, string $reason = 'Campaign completed'): Campaign
    {
        $reservation = $campaign->reservation()->withoutGlobalScopes()->first();

        if ($reservation instanceof WalletReservation) {
            // Releasing an already closed reservation is a no-op, so running
            // this twice cannot return the money twice.
            $this->wallets->release($reservation, null, 'Unused campaign budget returned');
        }

        DB::transaction(function () use ($campaign): void {
            if ($campaign->status->canTransitionTo(CampaignStatus::Completed)) {
                $campaign->status = CampaignStatus::Completed;
            }

            $campaign->completed_at = CarbonImmutable::now();
            $campaign->save();
        });

        $account = $campaign->adAccount()->withoutGlobalScopes()->first();

        if ($account !== null) {
            // Whatever the campaign never spent stops being committed, so the
            // account can take other work.
            $this->releaseAccountCommitment($account, $campaign, $campaign->captured_amount, final: true);
        }

        $this->audit->recordSystemEvent(
            action: AuditAction::CampaignCompleted,
            resource: $campaign,
            context: [
                'reason' => $reason,
                'captured' => $campaign->capturedAmount()->toDecimal(),
                'currency' => $campaign->currency,
            ],
            label: 'CampaignSpendReconciler',
        );

        return $campaign;
    }

    /**
     * The platform's fee earned on a given amount of spend.
     *
     * Recomputed from the cumulative figure every time. Accumulating a fee per
     * delta would round on each one, and a campaign synced hourly for a month
     * would drift by a visible amount.
     */
    private function feeDueOn(Campaign $campaign, int $spendToDate): int
    {
        $committed = $campaign->committedBudget()->minorUnits;

        if ($committed === 0 || $campaign->fee_total === 0) {
            return 0;
        }

        return (int) round($campaign->fee_total * $spendToDate / $committed);
    }

    /**
     * Draw the difference out of the hold: the advertising spend and the fee
     * on it, as separate entries so a client's statement says which is which.
     */
    private function capture(Campaign $campaign, int $spendToDate, int $delta): void
    {
        $reservation = $campaign->reservation()->withoutGlobalScopes()->first();

        if (! $reservation instanceof WalletReservation) {
            return;
        }

        $currency = $campaign->currency();
        $feeDue = $this->feeDueOn($campaign, $spendToDate);
        $feeAlreadyTaken = max(0, $campaign->captured_amount - $this->spendAlreadyTaken($campaign));

        $spendDelta = max(0, $spendToDate - $this->spendAlreadyTaken($campaign));
        $feeDelta = max(0, $feeDue - $feeAlreadyTaken);

        // Rounding can leave the two parts a unit off the total; the spend
        // line carries the difference so the capture matches the hold exactly.
        $accounted = $spendDelta + $feeDelta;

        if ($accounted !== $delta) {
            $spendDelta += $delta - $accounted;
        }

        DB::transaction(function () use ($campaign, $reservation, $currency, $spendDelta, $feeDelta): void {
            if ($spendDelta > 0) {
                $this->wallets->capture(
                    reservation: $reservation,
                    amount: Money::ofMinor($spendDelta, $currency),
                    description: "Advertising spend — {$campaign->name}",
                    metadata: ['campaign' => $campaign->public_id],
                    type: LedgerEntryType::CampaignSpend,
                );
            }

            if ($feeDelta > 0) {
                $this->wallets->capture(
                    reservation: $reservation->refresh(),
                    amount: Money::ofMinor($feeDelta, $currency),
                    description: "Management fee — {$campaign->name}",
                    metadata: ['campaign' => $campaign->public_id],
                    type: LedgerEntryType::ManagementFee,
                );
            }

            $campaign->captured_amount += $spendDelta + $feeDelta;
            $campaign->save();
        });

        $this->audit->recordSystemEvent(
            action: AuditAction::CampaignSpendCaptured,
            resource: $campaign,
            context: [
                'spend' => Money::ofMinor($spendDelta, $currency)->toDecimal(),
                'fee' => Money::ofMinor($feeDelta, $currency)->toDecimal(),
                'currency' => $campaign->currency,
            ],
            label: 'CampaignSpendReconciler',
        );
    }

    /**
     * How much of what has been captured so far was advertising spend rather
     * than fee, derived from the same ratio the fee is charged at.
     */
    private function spendAlreadyTaken(Campaign $campaign): int
    {
        $committed = $campaign->committedBudget()->minorUnits;

        if ($committed === 0 || $campaign->fee_total === 0) {
            return $campaign->captured_amount;
        }

        // captured = spend + fee_total * spend / committed, so
        // spend = captured * committed / (committed + fee_total).
        return (int) round(
            $campaign->captured_amount * $committed / ($committed + $campaign->fee_total)
        );
    }

    /**
     * Give an ad account back the headroom a campaign is no longer going to
     * use, so allocation can offer it to someone else.
     */
    private function releaseAccountCommitment(
        AdAccount $account,
        Campaign $campaign,
        int $spentSoFar,
        bool $final = false,
    ): void {
        DB::transaction(function () use ($account, $campaign, $spentSoFar, $final): void {
            /** @var AdAccount $locked */
            $locked = AdAccount::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $committed = $campaign->committedBudget()->minorUnits;

            // While running, the campaign's share shrinks to what is still
            // expected to be spent; at completion it goes entirely.
            $stillCommitted = $final ? 0 : max(0, $committed - $spentSoFar);

            // The difference against what this campaign is *currently*
            // recorded as holding — not against its original budget. Using the
            // budget would subtract the same headroom again on every sync.
            $release = max(0, $campaign->account_commitment - $stillCommitted);

            if ($release === 0) {
                return;
            }

            $locked->committed_amount = max(0, $locked->committed_amount - $release);
            $locked->save();

            $campaign->account_commitment = $stillCommitted;
            $campaign->save();
        });
    }
}
