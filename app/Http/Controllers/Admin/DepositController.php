<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Identity\Models\User;
use App\Domains\Payment\Actions\VerifyPayment;
use App\Domains\Payment\Enums\PaymentStatus;
use App\Domains\Payment\Exceptions\InvalidPaymentTransition;
use App\Domains\Payment\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The deposit verification queue (spec §34, §41 Finance).
 *
 * Verifying is what turns a client's claim into balance, so it is gated on
 * `payments.verify` — a permission no client or agency role holds.
 */
final class DepositController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Payment::class);

        $status = $request->query('status');

        $payments = Payment::acrossTenants()
            ->with(['organization:id,public_id,name,tenant_id', 'organization.tenant:id,name'])
            ->when(
                is_string($status) && $status !== '',
                fn ($query) => $query->where('status', $status),
                // The default view is the work queue.
                fn ($query) => $query->where('status', PaymentStatus::AwaitingVerification),
            )
            ->orderBy('submitted_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Payment $payment): array => [
                'id' => $payment->public_id,
                'reference' => $payment->reference,
                'organization' => $payment->organization->name,
                'tenant' => $payment->organization->tenant->name,
                'method' => $payment->method->value,
                'methodLabel' => $payment->method->label(),
                'amount' => $payment->amountMoney()->format(),
                'status' => $payment->status->value,
                'statusLabel' => $payment->status->label(),
                'externalReference' => $payment->external_reference,
                'hasProof' => $payment->hasProof(),
                'submittedAt' => $payment->submitted_at?->toIso8601String(),
                'waitingDays' => $payment->submitted_at?->diffInDays(now()),
                'proofUrl' => $payment->hasProof()
                    ? route('admin.finance.deposits.proof', $payment->public_id)
                    : null,
            ]);

        return Inertia::render('Admin/Finance/Deposits', [
            'payments' => $payments,
            'filters' => ['status' => $status],
            'statuses' => array_map(
                static fn (PaymentStatus $case): array => [
                    'value' => $case->value,
                    'label' => $case->label(),
                ],
                PaymentStatus::cases(),
            ),
            'counts' => [
                'awaiting' => Payment::acrossTenants()
                    ->where('status', PaymentStatus::AwaitingVerification)
                    ->count(),
                'verifiedToday' => Payment::acrossTenants()
                    ->where('status', PaymentStatus::Verified)
                    ->whereDate('verified_at', today())
                    ->count(),
            ],
        ]);
    }

    public function verify(Request $request, Payment $payment): RedirectResponse
    {
        Gate::authorize('verify', $payment);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $verifier */
        $verifier = $request->user();

        try {
            app(VerifyPayment::class)->handle($payment, $verifier, $validated['note'] ?? null);
        } catch (InvalidPaymentTransition $exception) {
            throw ValidationException::withMessages(['payment' => $exception->getMessage()]);
        }

        return back()->with('success', "Deposit {$payment->reference} verified and credited.");
    }

    public function reject(Request $request, Payment $payment): RedirectResponse
    {
        Gate::authorize('verify', $payment);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Tell the client why this deposit could not be confirmed.',
        ]);

        /** @var User $verifier */
        $verifier = $request->user();

        try {
            app(VerifyPayment::class)->reject($payment, $verifier, $validated['reason']);
        } catch (InvalidPaymentTransition $exception) {
            throw ValidationException::withMessages(['payment' => $exception->getMessage()]);
        }

        return back()->with('success', "Deposit {$payment->reference} rejected.");
    }
}
