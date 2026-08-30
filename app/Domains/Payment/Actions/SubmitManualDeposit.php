<?php

declare(strict_types=1);

namespace App\Domains\Payment\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Client\Exceptions\RejectedUpload;
use App\Domains\Client\Services\DocumentStorage;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\PaymentMethod;
use App\Domains\Payment\Enums\PaymentStatus;
use App\Domains\Payment\Models\Payment;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Wallet\Services\WalletService;
use App\Support\Values\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A client tells the platform they have sent money (spec §34).
 *
 * Nothing is credited here. The submission is a claim, and it sits in
 * AWAITING_VERIFICATION until someone in finance confirms the money actually
 * arrived. Crediting on a client's say-so would let anyone with an account mint
 * balance.
 */
final class SubmitManualDeposit
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly DocumentStorage $storage,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @throws RejectedUpload when the proof file fails validation
     * @throws ValidationException
     */
    public function handle(
        Organization $organization,
        User $submitter,
        Money $amount,
        PaymentMethod $method,
        string $externalReference,
        ?UploadedFile $proof = null,
        ?Carbon $paidAt = null,
        ?string $idempotencyKey = null,
    ): Payment {
        $this->guard($organization, $amount, $method, $proof);

        // A retried submission must find the original rather than create a
        // second claim for the same transfer (spec §30).
        if ($idempotencyKey !== null) {
            $existing = Payment::query()
                ->where('organization_id', $organization->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $wallet = $this->wallets->walletFor($organization, $amount->currency->code);

        // Stored before the transaction: the file write is the part that can
        // fail slowly, and holding a database transaction open across it would
        // keep a lock for the duration of an upload.
        $stored = $proof !== null
            ? $this->storage->store($proof, $organization, 'payment-proof')
            : null;

        try {
            return DB::transaction(function () use (
                $organization,
                $submitter,
                $amount,
                $method,
                $externalReference,
                $stored,
                $paidAt,
                $idempotencyKey,
                $wallet,
            ): Payment {
                $payment = new Payment([
                    'organization_id' => $organization->getKey(),
                    'wallet_id' => $wallet->getKey(),
                    'reference' => Payment::generateReference(),
                    'method' => $method,
                    'amount' => $amount->minorUnits,
                    'currency' => $amount->currency->code,
                    'status' => PaymentStatus::AwaitingVerification,
                    'idempotency_key' => $idempotencyKey,
                    'external_reference' => $externalReference,
                    'submitted_at' => Carbon::now(),
                    'paid_at' => $paidAt,
                    'created_by' => $submitter->getKey(),
                ]);

                $payment->tenant_id = $organization->tenant_id;

                if ($stored !== null) {
                    $payment->proof_disk = $stored->disk;
                    $payment->proof_path = $stored->path;
                    $payment->proof_filename = $stored->originalFilename;
                }

                $payment->save();

                $this->audit->record(
                    action: AuditAction::DepositSubmitted,
                    resource: $payment,
                    after: [
                        'amount' => $amount->toDecimal(),
                        'currency' => $amount->currency->code,
                        'method' => $method->value,
                        'reference' => $externalReference,
                    ],
                    organization: $organization,
                    actor: $submitter,
                );

                return $payment;
            });
        } catch (\Throwable $exception) {
            // Without a row, nothing can ever reference the uploaded proof.
            if ($stored !== null) {
                $this->storage->delete($stored->path);
            }

            throw $exception;
        }
    }

    private function guard(
        Organization $organization,
        Money $amount,
        PaymentMethod $method,
        ?UploadedFile $proof,
    ): void {
        if (! $method->requiresManualVerification()) {
            throw ValidationException::withMessages([
                'method' => 'That payment method is processed by a gateway, not submitted manually.',
            ]);
        }

        $minimum = Money::ofMinor(
            (int) config('platform.finance.minimum_deposit_minor'),
            $organization->default_currency,
        );

        if ($amount->lessThan($minimum)) {
            throw ValidationException::withMessages([
                'amount' => "The smallest deposit we can accept is {$minimum->format()}.",
            ]);
        }

        if ($method->requiresProof() && $proof === null) {
            throw ValidationException::withMessages([
                'proof' => 'Attach a receipt or screenshot showing the transfer.',
            ]);
        }
    }
}
