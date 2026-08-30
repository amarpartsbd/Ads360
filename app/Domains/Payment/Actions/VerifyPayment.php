<?php

declare(strict_types=1);

namespace App\Domains\Payment\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\PaymentStatus;
use App\Domains\Payment\Exceptions\InvalidPaymentTransition;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Notifications\DepositOutcome;
use App\Domains\Wallet\Services\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Credits a confirmed payment to the wallet (spec §33, §34).
 *
 * This is the only place money enters the platform, and it is deliberately
 * boring: lock the payment row, re-check the state under the lock, write one
 * ledger entry, point the payment at it, commit.
 *
 * Three independent things stop a double credit:
 *
 *   - the payment row is locked and its state re-read inside the transaction;
 *   - a unique index allows one payment per ledger entry;
 *   - a second unique index allows one DEPOSIT entry per payment.
 *
 * Two finance staff clicking verify at the same instant, or a webhook retried
 * mid-flight, therefore cannot both succeed.
 */
final class VerifyPayment
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @throws InvalidPaymentTransition
     */
    public function handle(Payment $payment, User $verifier, ?string $note = null): Payment
    {
        $verified = DB::transaction(function () use ($payment, $verifier, $note): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()
                ->withoutGlobalScopes()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Re-read under the lock. The state that mattered when the page was
            // rendered is not the state that matters now.
            if ($locked->status === PaymentStatus::Verified) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo(PaymentStatus::Verified)) {
                throw InvalidPaymentTransition::between($locked->status, PaymentStatus::Verified);
            }

            $wallet = $locked->wallet()->withoutGlobalScopes()->firstOrFail();

            $entry = $this->wallets->deposit(
                wallet: $wallet,
                amount: $locked->amountMoney(),
                description: "Deposit {$locked->reference}",
                reference: $locked,
                metadata: [
                    'method' => $locked->method->value,
                    'external_reference' => $locked->external_reference,
                ],
                actor: $verifier,
            );

            $locked->forceFill([
                'status' => PaymentStatus::Verified,
                'verified_at' => Carbon::now(),
                'verified_by' => $verifier->getKey(),
                'ledger_entry_id' => $entry->getKey(),
                'paid_at' => $locked->paid_at ?? Carbon::now(),
                'metadata' => [...$locked->metadata, 'verification_note' => $note],
            ])->save();

            $this->audit->record(
                action: AuditAction::DepositApproved,
                resource: $locked,
                before: ['status' => $payment->status->value],
                after: [
                    'status' => PaymentStatus::Verified->value,
                    'amount' => $locked->amountMoney()->toDecimal(),
                    'ledger_entry' => $entry->public_id,
                ],
                context: ['note' => $note],
                organization: $locked->organization()->withoutGlobalScopes()->first(),
                actor: $verifier,
            );

            $payment->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });

        $this->notify($verified, approved: true, reason: $note);

        return $verified;
    }

    /**
     * Refuse a payment. Nothing is credited, and the client is told why —
     * a rejected deposit with no explanation becomes a support ticket.
     */
    public function reject(Payment $payment, User $verifier, string $reason): Payment
    {
        $rejected = DB::transaction(function () use ($payment, $verifier, $reason): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()
                ->withoutGlobalScopes()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canTransitionTo(PaymentStatus::Rejected)) {
                throw InvalidPaymentTransition::between($locked->status, PaymentStatus::Rejected);
            }

            $locked->forceFill([
                'status' => PaymentStatus::Rejected,
                'rejection_reason' => $reason,
                'verified_at' => Carbon::now(),
                'verified_by' => $verifier->getKey(),
            ])->save();

            $this->audit->record(
                action: AuditAction::DepositRejected,
                resource: $locked,
                before: ['status' => $payment->status->value],
                after: ['status' => PaymentStatus::Rejected->value],
                context: ['reason' => $reason],
                organization: $locked->organization()->withoutGlobalScopes()->first(),
                actor: $verifier,
            );

            $payment->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });

        $this->notify($rejected, approved: false, reason: $reason);

        return $rejected;
    }

    private function notify(Payment $payment, bool $approved, ?string $reason): void
    {
        $organization = $payment->organization()->withoutGlobalScopes()->first();

        if ($organization === null) {
            return;
        }

        foreach ($organization->activeMembers()->get() as $member) {
            $member->notify(new DepositOutcome($payment, $approved, $reason));
        }
    }
}
