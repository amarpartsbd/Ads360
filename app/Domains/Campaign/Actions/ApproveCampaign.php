<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Exceptions\CampaignException;
use App\Domains\Campaign\Jobs\PublishCampaign;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Identity\Models\User;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Services\WalletService;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Approves a campaign and commits the resources it needs (spec §21, §22, §19).
 *
 * This is the moment the platform takes on obligations: the client's money is
 * held, and an ad account is committed to the campaign. Both must happen or
 * neither, so both happen in one transaction — a campaign approved with money
 * held but no account would sit unpublishable with the client's balance
 * frozen, and one with an account but no hold would spend money nobody
 * reserved.
 *
 * **Lock order: wallet, then ad account.** The reservation locks the wallet
 * row; allocation locks the ad account row. Every path that takes both takes
 * them in that order. Two approvals that acquired them in opposite orders
 * would deadlock, and Phase 2 has already been bitten by exactly that.
 *
 * The amount held is the total frozen at submission, not a fresh calculation.
 * A pricing plan that changed between submission and approval does not change
 * what the client agreed to.
 */
final class ApproveCampaign
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly AllocateAdAccount $allocate,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Campaign $campaign, User $approver, ?string $notes = null): Campaign
    {
        $this->assertReviewable($campaign, $approver);

        $wallet = $this->walletFor($campaign);
        $charged = Money::ofMinor($campaign->charged_total, $campaign->currency());
        $before = AuditRecorder::snapshot($campaign);

        DB::transaction(function () use ($campaign, $wallet, $charged, $approver, $notes): void {
            // 1. Wallet first. Fails closed on an insufficient balance, before
            //    any ad account has been committed to a campaign that cannot
            //    pay for itself.
            $reservation = $this->wallets->reserve(
                wallet: $wallet,
                amount: $charged,
                reference: $campaign,
                expiresAt: $campaign->ends_at === null ? null : Carbon::instance($campaign->ends_at->toDateTime()),
                actor: $approver,
            );

            // 2. Ad account second, in the same transaction, so a failure here
            //    rolls the hold back rather than stranding it.
            $account = $this->allocate->handle($campaign, $approver);

            $campaign->status = CampaignStatus::Approved;
            $campaign->wallet_reservation_id = $reservation->getKey();
            $campaign->ad_account_id = $account->getKey();
            $campaign->ad_account_pool_id = $account->pools()->first()?->getKey();
            $campaign->reviewed_at = CarbonImmutable::now();
            $campaign->reviewed_by = $approver->getKey();
            $campaign->review_notes = $notes;
            $campaign->last_error = null;

            $campaign->save();
        });

        $this->audit->recordChange(
            action: AuditAction::CampaignApproved,
            resource: $campaign,
            before: $before,
            context: [
                'charged_total' => $charged->toDecimal(),
                'currency' => $campaign->currency,
                'ad_account' => $campaign->adAccount()->withoutGlobalScopes()->first()?->public_id,
            ],
            actor: $approver,
        );

        // Queued only once the transaction has committed. Dispatching inside
        // it can hand a worker a campaign id that is rolled back a moment
        // later, and the worker would find nothing to publish (Rule 16).
        PublishCampaign::dispatch($campaign->getKey())->afterCommit();

        return $campaign;
    }

    private function assertReviewable(Campaign $campaign, User $approver): void
    {
        if ($campaign->status !== CampaignStatus::PendingReview) {
            throw CampaignException::notUnderReview();
        }

        // Belt and braces with the policy: separation of duties is the reason
        // review exists, so it is checked where the decision is made too.
        if ($campaign->submitted_by !== null && $campaign->submitted_by === $approver->getKey()) {
            throw CampaignException::cannotReviewOwnSubmission();
        }

        if (! $campaign->status->canTransitionTo(CampaignStatus::Approved)) {
            throw CampaignException::invalidTransition($campaign->status, CampaignStatus::Approved);
        }
    }

    /**
     * Read without the tenant scope and matched on organization and currency:
     * approval is performed by platform staff, for whom no tenant is bound.
     */
    private function walletFor(Campaign $campaign): Wallet
    {
        $wallet = Wallet::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $campaign->organization_id)
            ->where('currency', $campaign->currency)
            ->first();

        if ($wallet === null) {
            throw CampaignException::currencyMismatch();
        }

        return $wallet;
    }
}
