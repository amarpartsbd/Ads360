<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Client\Enums\DocumentMediaType;
use App\Domains\Client\Exceptions\RejectedUpload;
use App\Domains\Client\Services\DocumentStorage;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Actions\SubmitManualDeposit;
use App\Domains\Payment\Enums\PaymentMethod;
use App\Domains\Payment\Models\Payment;
use App\Domains\Tenant\Services\TenantContext;
use App\Domains\Wallet\Models\LedgerEntry;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Services\WalletService;
use App\Support\Values\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The client's own wallet (spec §14 Wallet, §34).
 *
 * Every figure here is computed server-side and sent formatted. The browser
 * renders money; it never calculates it (Rule 8).
 */
final class WalletController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly WalletService $wallets,
    ) {}

    public function overview(): Response
    {
        $wallet = $this->wallet();

        Gate::authorize('view', $wallet);

        return Inertia::render('Client/Wallet/Overview', [
            'wallet' => $this->serialiseWallet($wallet),
            'recentEntries' => $this->entries($wallet, limit: 10),
            'pendingDeposits' => $this->pendingDeposits($wallet),
            'can' => [
                'deposit' => Gate::allows('deposit', $wallet),
            ],
        ]);
    }

    public function transactions(Request $request): Response
    {
        $wallet = $this->wallet();

        Gate::authorize('view', $wallet);

        $entries = $wallet->entries()
            ->when($request->query('type'), fn ($query, $type) => $query->where('type', $type))
            ->latest('created_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (LedgerEntry $entry): array => $this->serialiseEntry($entry));

        return Inertia::render('Client/Wallet/Transactions', [
            'wallet' => $this->serialiseWallet($wallet),
            'entries' => $entries,
            'filters' => ['type' => $request->query('type')],
        ]);
    }

    public function deposits(): Response
    {
        $wallet = $this->wallet();

        Gate::authorize('view', $wallet);

        return Inertia::render('Client/Wallet/AddFunds', [
            'wallet' => $this->serialiseWallet($wallet),
            'methods' => array_map(
                static fn (PaymentMethod $method): array => [
                    'value' => $method->value,
                    'label' => $method->label(),
                    'requiresReference' => $method->requiresExternalReference(),
                    'requiresProof' => $method->requiresProof(),
                    'manual' => $method->requiresManualVerification(),
                ],
                // Only manually verified methods are offered: the gateway
                // adapters exist but no merchant account is live yet (spec §95).
                array_values(array_filter(
                    PaymentMethod::cases(),
                    static fn (PaymentMethod $method): bool => $method->requiresManualVerification(),
                )),
            ),
            'minimumDeposit' => Money::ofMinor(
                (int) config('platform.finance.minimum_deposit_minor'),
                $wallet->currency,
            )->jsonSerialize(),
            'upload' => [
                'maxBytes' => DocumentStorage::MAX_BYTES,
                'acceptedExtensions' => DocumentMediaType::allowedExtensions(),
            ],
            'recent' => $this->recentPayments($wallet),
        ]);
    }

    public function storeDeposit(Request $request): RedirectResponse
    {
        $wallet = $this->wallet();

        Gate::authorize('deposit', $wallet);

        $validated = $request->validate([
            // A decimal string, not a float: it is parsed by Money, which
            // rejects anything with more precision than the currency allows.
            'amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'external_reference' => ['required', 'string', 'max:128'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
            'proof' => ['nullable', 'file', 'max:'.(DocumentStorage::MAX_BYTES / 1024)],
        ], [
            'amount.regex' => 'Enter an amount such as 25000 or 25000.50.',
            'external_reference.required' => 'Enter the transaction reference from your bank or wallet app.',
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            app(SubmitManualDeposit::class)->handle(
                organization: $this->context->requireOrganization(),
                submitter: $user,
                amount: Money::of($validated['amount'], $wallet->currency),
                method: PaymentMethod::from($validated['method']),
                externalReference: $validated['external_reference'],
                proof: $request->file('proof'),
                paidAt: isset($validated['paid_at'])
                    ? \Illuminate\Support\Carbon::parse($validated['paid_at'])
                    : null,
                // Derived from the submission itself, so a double-clicked form
                // or a retried request cannot create two claims (spec §30).
                idempotencyKey: hash('sha256', implode('|', [
                    $wallet->getKey(),
                    $validated['amount'],
                    $validated['method'],
                    $validated['external_reference'],
                ])),
            );
        } catch (RejectedUpload $exception) {
            throw ValidationException::withMessages(['proof' => $exception->getMessage()]);
        }

        return redirect()
            ->route('client.wallet.overview')
            ->with('success', 'Your deposit has been submitted and is awaiting verification.');
    }

    public function invoices(): Response
    {
        $organization = $this->context->requireOrganization();

        Gate::authorize('viewAny', Invoice::class);

        $invoices = Invoice::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', '!=', 'DRAFT')
            ->latest('issued_on')
            ->paginate(25)
            ->through(fn (Invoice $invoice): array => [
                'id' => $invoice->public_id,
                'number' => $invoice->number,
                'kind' => $invoice->kind->value,
                'kindLabel' => $invoice->kind->label(),
                'status' => $invoice->status->value,
                'statusLabel' => $invoice->status->label(),
                'total' => $invoice->totalMoney()->format(),
                'outstanding' => $invoice->outstanding()->format(),
                'issuedOn' => $invoice->issued_on?->toDateString(),
                'dueOn' => $invoice->due_on?->toDateString(),
            ]);

        return Inertia::render('Client/Wallet/Invoices', [
            'invoices' => $invoices,
        ]);
    }

    private function wallet(): Wallet
    {
        return $this->wallets->walletFor($this->context->requireOrganization());
    }

    /**
     * @return array<string, mixed>
     */
    private function serialiseWallet(Wallet $wallet): array
    {
        return [
            'id' => $wallet->public_id,
            'currency' => $wallet->currency,
            'status' => $wallet->status->value,
            'statusLabel' => $wallet->status->label(),
            'available' => $wallet->availableBalance()->jsonSerialize(),
            'reserved' => $wallet->reservedBalance()->jsonSerialize(),
            'total' => $wallet->totalBalance()->jsonSerialize(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entries(Wallet $wallet, int $limit): array
    {
        return $wallet->entries()
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (LedgerEntry $entry): array => $this->serialiseEntry($entry))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serialiseEntry(LedgerEntry $entry): array
    {
        return [
            'id' => $entry->public_id,
            'type' => $entry->type->value,
            'typeLabel' => $entry->type->label(),
            'description' => $entry->description,
            'isCredit' => $entry->isCredit(),
            'amount' => $entry->magnitude()->format(),
            'balanceAfter' => Money::ofMinor($entry->balance_snapshot, $entry->currency)->format(),
            'at' => $entry->created_at->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingDeposits(Wallet $wallet): array
    {
        return Payment::query()
            ->where('wallet_id', $wallet->getKey())
            ->whereIn('status', ['PENDING', 'PROCESSING', 'AWAITING_VERIFICATION'])
            ->latest('submitted_at')
            ->get()
            ->map(fn (Payment $payment): array => $this->serialisePayment($payment))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentPayments(Wallet $wallet): array
    {
        return Payment::query()
            ->where('wallet_id', $wallet->getKey())
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (Payment $payment): array => $this->serialisePayment($payment))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serialisePayment(Payment $payment): array
    {
        return [
            'id' => $payment->public_id,
            'reference' => $payment->reference,
            'method' => $payment->method->value,
            'methodLabel' => $payment->method->label(),
            'amount' => $payment->amountMoney()->format(),
            'status' => $payment->status->value,
            'statusLabel' => $payment->status->label(),
            'externalReference' => $payment->external_reference,
            'rejectionReason' => $payment->rejection_reason,
            'submittedAt' => $payment->submitted_at?->toIso8601String(),
        ];
    }
}
