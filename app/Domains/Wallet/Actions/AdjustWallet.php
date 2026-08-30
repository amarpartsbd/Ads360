<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use App\Domains\System\Enums\ApprovableAction;
use App\Domains\System\Models\ApprovalRequest;
use App\Domains\System\Services\ApprovalService;
use App\Domains\Wallet\DTOs\LedgerMovement;
use App\Domains\Wallet\Enums\LedgerEntryType;
use App\Domains\Wallet\Models\LedgerEntry;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Services\WalletService;
use App\Support\Values\Money;
use Illuminate\Validation\ValidationException;

/**
 * A manual correction to a wallet balance (spec §25, §31).
 *
 * Adjustments are the most dangerous thing finance can do — they create or
 * destroy client balance without a payment behind them — so above a configured
 * threshold they do not execute on request. They become an approval request
 * that someone else has to sign off, and only then does the ledger move.
 *
 * Returns either the ledger entry (executed immediately) or the approval
 * request (waiting on a checker), and the caller reports accordingly.
 */
final class AdjustWallet
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly ApprovalService $approvals,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  bool  $isCredit  true adds balance, false removes it
     */
    public function handle(
        Wallet $wallet,
        Money $amount,
        bool $isCredit,
        string $reason,
        User $actor,
    ): LedgerEntry|ApprovalRequest {
        if (! $amount->isPositive()) {
            throw ValidationException::withMessages([
                'amount' => 'An adjustment must be for a positive amount. Choose a direction instead.',
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'An adjustment must record why it was made.',
            ]);
        }

        if ($this->approvals->isRequired(ApprovableAction::WalletAdjustment, $amount)) {
            return $this->approvals->request(
                action: ApprovableAction::WalletAdjustment,
                requester: $actor,
                summary: sprintf(
                    '%s %s on wallet %s',
                    $isCredit ? 'Credit' : 'Debit',
                    $amount->format(),
                    $wallet->public_id,
                ),
                // Enough to carry the adjustment out later without anything
                // being retyped, so what executes is what was approved.
                payload: [
                    'wallet_id' => $wallet->getKey(),
                    'amount_minor' => $amount->minorUnits,
                    'currency' => $amount->currency->code,
                    'is_credit' => $isCredit,
                    'reason' => $reason,
                ],
                amount: $amount,
                organization: $wallet->organization()->withoutGlobalScopes()->first(),
                reason: $reason,
            );
        }

        return $this->execute($wallet, $amount, $isCredit, $reason, $actor);
    }

    /**
     * Carry out an adjustment that has cleared approval.
     *
     * Separate from handle() so an approved request executes the recorded
     * payload rather than re-deriving it from whatever the interface sends the
     * second time.
     */
    public function executeApproved(ApprovalRequest $request, User $executor): LedgerEntry
    {
        $payload = $request->payload;

        /** @var Wallet $wallet */
        $wallet = Wallet::query()->withoutGlobalScopes()->findOrFail($payload['wallet_id']);

        $entry = $this->execute(
            $wallet,
            Money::ofMinor((int) $payload['amount_minor'], (string) $payload['currency']),
            (bool) $payload['is_credit'],
            (string) $payload['reason'],
            $executor,
            $request,
        );

        $this->approvals->markExecuted($request, $entry);

        return $entry;
    }

    private function execute(
        Wallet $wallet,
        Money $amount,
        bool $isCredit,
        string $reason,
        User $actor,
        ?ApprovalRequest $request = null,
    ): LedgerEntry {
        $movement = new LedgerMovement(
            type: LedgerEntryType::Adjustment,
            amount: $amount,
            isCredit: $isCredit,
            description: $reason,
            reference: $request,
            metadata: [
                'adjustment' => true,
                'approval_request' => $request?->public_id,
            ],
        );

        $entry = $this->wallets->postGroup($wallet, [$movement], $actor)->first();

        $this->audit->record(
            action: AuditAction::WalletAdjusted,
            resource: $entry,
            after: [
                'direction' => $isCredit ? 'credit' : 'debit',
                'amount' => $amount->toDecimal(),
                'currency' => $amount->currency->code,
                'balance_after' => $entry->balance_snapshot,
            ],
            context: [
                'reason' => $reason,
                'approval_request' => $request?->public_id,
            ],
            organization: $wallet->organization()->withoutGlobalScopes()->first(),
            actor: $actor,
        );

        return $entry;
    }
}
