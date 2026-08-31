<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\PaymentStatus;
use App\Domains\Payment\Models\Payment;
use App\Domains\System\Enums\ApprovableAction;
use App\Domains\System\Models\ApprovalRequest;
use App\Domains\System\Services\ApprovalService;
use App\Domains\Wallet\DTOs\LedgerMovement;
use App\Domains\Wallet\Enums\LedgerEntryType;
use App\Domains\Wallet\Models\LedgerEntry;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Services\WalletService;
use App\Support\Values\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Returns money to a client (spec §31, §25).
 *
 * A refund debits the wallet: the balance leaves the platform and goes back to
 * the client's own payment method. It is not a reversal of the original
 * deposit — the deposit really happened, and rewriting it would make the
 * ledger disagree with the bank.
 *
 * Above the configured threshold it needs a second approver, like an
 * adjustment.
 */
final class RefundToClient
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly ApprovalService $approvals,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        Wallet $wallet,
        Money $amount,
        string $reason,
        User $actor,
        ?Payment $againstPayment = null,
    ): LedgerEntry|ApprovalRequest {
        $this->guard($wallet, $amount, $reason, $againstPayment);

        // The organization is passed so a high-risk client raises the bar
        // even below the size threshold (spec §12).
        if ($this->approvals->isRequired(ApprovableAction::Refund, $amount, $wallet->organization)) {
            return $this->approvals->request(
                action: ApprovableAction::Refund,
                requester: $actor,
                summary: sprintf('Refund %s from wallet %s', $amount->format(), $wallet->public_id),
                payload: [
                    'wallet_id' => $wallet->getKey(),
                    'amount_minor' => $amount->minorUnits,
                    'currency' => $amount->currency->code,
                    'reason' => $reason,
                    'payment_id' => $againstPayment?->getKey(),
                ],
                amount: $amount,
                organization: $wallet->organization()->withoutGlobalScopes()->first(),
                reason: $reason,
            );
        }

        return $this->execute($wallet, $amount, $reason, $actor, $againstPayment);
    }

    public function executeApproved(ApprovalRequest $request, User $executor): LedgerEntry
    {
        $payload = $request->payload;

        /** @var Wallet $wallet */
        $wallet = Wallet::query()->withoutGlobalScopes()->findOrFail($payload['wallet_id']);

        $payment = isset($payload['payment_id'])
            ? Payment::query()->withoutGlobalScopes()->find($payload['payment_id'])
            : null;

        $entry = $this->execute(
            $wallet,
            Money::ofMinor((int) $payload['amount_minor'], (string) $payload['currency']),
            (string) $payload['reason'],
            $executor,
            $payment,
            $request,
        );

        $this->approvals->markExecuted($request, $entry);

        return $entry;
    }

    private function execute(
        Wallet $wallet,
        Money $amount,
        string $reason,
        User $actor,
        ?Payment $againstPayment,
        ?ApprovalRequest $request = null,
    ): LedgerEntry {
        return DB::transaction(function () use ($wallet, $amount, $reason, $actor, $againstPayment, $request): LedgerEntry {
            $entry = $this->wallets->postGroup($wallet, [
                new LedgerMovement(
                    type: LedgerEntryType::Refund,
                    amount: $amount,
                    // A debit: the money is leaving the wallet on its way back
                    // to the client.
                    isCredit: false,
                    description: $reason,
                    reference: $againstPayment ?? $request,
                    metadata: [
                        'refund' => true,
                        'against_payment' => $againstPayment?->reference,
                        'approval_request' => $request?->public_id,
                    ],
                ),
            ], $actor)->first();

            $this->audit->record(
                action: AuditAction::RefundIssued,
                resource: $entry,
                after: [
                    'amount' => $amount->toDecimal(),
                    'currency' => $amount->currency->code,
                    'balance_after' => $entry->balance_snapshot,
                ],
                context: [
                    'reason' => $reason,
                    'payment' => $againstPayment?->reference,
                    'approval_request' => $request?->public_id,
                ],
                organization: $wallet->organization()->withoutGlobalScopes()->first(),
                actor: $actor,
            );

            return $entry;
        });
    }

    private function guard(Wallet $wallet, Money $amount, string $reason, ?Payment $payment): void
    {
        if (! $amount->isPositive()) {
            throw ValidationException::withMessages([
                'amount' => 'A refund must be for a positive amount.',
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'A refund must record why it was issued.',
            ]);
        }

        if ($payment !== null) {
            if ($payment->wallet_id !== $wallet->getKey()) {
                throw ValidationException::withMessages([
                    'payment' => 'That payment belongs to a different wallet.',
                ]);
            }

            if ($payment->status !== PaymentStatus::Verified) {
                throw ValidationException::withMessages([
                    'payment' => 'Only a verified payment can be refunded.',
                ]);
            }

            // Refunding more than came in would turn a refund into a payout.
            $alreadyRefunded = LedgerEntry::query()
                ->withoutGlobalScopes()
                ->where('type', LedgerEntryType::Refund)
                ->where('reference_type', Payment::class)
                ->where('reference_id', (string) $payment->getKey())
                ->sum('debit');

            $remaining = $payment->amount - (int) $alreadyRefunded;

            if ($amount->minorUnits > $remaining) {
                $left = Money::ofMinor(max(0, $remaining), $payment->currency);

                throw ValidationException::withMessages([
                    'amount' => "Only {$left->format()} of that payment is still refundable.",
                ]);
            }
        }
    }
}
