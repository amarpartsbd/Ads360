<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Billing\Models\ExchangeRate;
use App\Domains\Billing\Models\PricingPlan;
use App\Domains\Billing\Services\ExchangeRateService;
use App\Domains\Identity\Models\User;
use App\Domains\System\Models\ApprovalRequest;
use App\Domains\Wallet\Actions\AdjustWallet;
use App\Domains\Wallet\Actions\RefundToClient;
use App\Domains\Wallet\Models\LedgerEntry;
use App\Domains\Wallet\Models\Wallet;
use App\Support\Values\Currency;
use App\Support\Values\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform finance: wallets, the ledger, adjustments, rates and pricing
 * (spec §41 Finance).
 */
final class FinanceController
{
    public function wallets(Request $request): Response
    {
        Gate::authorize('viewAny', Wallet::class);

        $search = trim((string) $request->query('search', ''));

        $wallets = Wallet::acrossTenants()
            ->with(['organization:id,public_id,name,tenant_id', 'organization.tenant:id,name'])
            ->when($search !== '', fn ($query) => $query->whereHas(
                'organization',
                fn ($organization) => $organization->where('name', 'ilike', '%'.$search.'%'),
            ))
            ->orderByDesc('available_balance_cached')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Wallet $wallet): array => [
                'id' => $wallet->public_id,
                'organization' => $wallet->organization->name,
                'tenant' => $wallet->organization->tenant->name,
                'currency' => $wallet->currency,
                'available' => $wallet->availableBalance()->format(),
                'reserved' => $wallet->reservedBalance()->format(),
                'total' => $wallet->totalBalance()->format(),
                'status' => $wallet->status->value,
                'statusLabel' => $wallet->status->label(),
                'url' => route('admin.finance.wallets.show', $wallet->public_id),
            ]);

        return Inertia::render('Admin/Finance/Wallets', [
            'wallets' => $wallets,
            'filters' => ['search' => $search],
            'liability' => $this->clientLiability(),
        ]);
    }

    public function showWallet(Wallet $wallet): Response
    {
        Gate::authorize('view', $wallet);

        $wallet->load(['organization:id,public_id,name,tenant_id', 'organization.tenant:id,name']);

        return Inertia::render('Admin/Finance/WalletDetail', [
            'wallet' => [
                'id' => $wallet->public_id,
                'organization' => $wallet->organization->name,
                'tenant' => $wallet->organization->tenant->name,
                'currency' => $wallet->currency,
                'available' => $wallet->availableBalance()->format(),
                'reserved' => $wallet->reservedBalance()->format(),
                'total' => $wallet->totalBalance()->format(),
                'status' => $wallet->status->value,
                'statusLabel' => $wallet->status->label(),
                // Recomputed from the ledger on every view: drift between the
                // cache and the entries is a defect worth surfacing loudly
                // rather than a number to quietly trust (spec §78).
                'reconciled' => $wallet->isReconciled(),
            ],
            'entries' => $wallet->entries()
                ->with('author:id,name')
                ->latest('created_at')
                ->paginate(50)
                ->through(fn (LedgerEntry $entry): array => [
                    'id' => $entry->public_id,
                    'type' => $entry->type->value,
                    'typeLabel' => $entry->type->label(),
                    'description' => $entry->description,
                    'isCredit' => $entry->isCredit(),
                    'amount' => $entry->magnitude()->format(),
                    'balanceAfter' => Money::ofMinor($entry->balance_snapshot, $entry->currency)->format(),
                    'reservedAfter' => Money::ofMinor($entry->reserved_snapshot, $entry->currency)->format(),
                    'author' => $entry->author->name ?? 'System',
                    'group' => $entry->transaction_group_id,
                    'at' => $entry->created_at->toIso8601String(),
                ]),
            'can' => [
                'adjust' => Gate::allows('adjust', $wallet),
                'refund' => Gate::allows('refund', $wallet),
            ],
            'thresholds' => [
                'adjustment' => Money::ofMinor(
                    (int) config('platform.finance.maker_checker.wallet_adjustment_minor'),
                    $wallet->currency,
                )->format(),
                'refund' => Money::ofMinor(
                    (int) config('platform.finance.maker_checker.refund_minor'),
                    $wallet->currency,
                )->format(),
            ],
        ]);
    }

    public function adjust(Request $request, Wallet $wallet): RedirectResponse
    {
        Gate::authorize('adjust', $wallet);

        $validated = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'direction' => ['required', 'in:credit,debit'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $result = app(AdjustWallet::class)->handle(
            wallet: $wallet,
            amount: Money::of($validated['amount'], $wallet->currency),
            isCredit: $validated['direction'] === 'credit',
            reason: $validated['reason'],
            actor: $actor,
        );

        return back()->with(
            'success',
            $result instanceof ApprovalRequest
                ? 'That adjustment needs a second approval. It is now in the approvals queue.'
                : 'Adjustment posted.',
        );
    }

    public function refund(Request $request, Wallet $wallet): RedirectResponse
    {
        Gate::authorize('refund', $wallet);

        $validated = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $result = app(RefundToClient::class)->handle(
            wallet: $wallet,
            amount: Money::of($validated['amount'], $wallet->currency),
            reason: $validated['reason'],
            actor: $actor,
        );

        return back()->with(
            'success',
            $result instanceof ApprovalRequest
                ? 'That refund needs a second approval. It is now in the approvals queue.'
                : 'Refund issued.',
        );
    }

    public function exchangeRates(): Response
    {
        Gate::authorize('viewAny', ExchangeRate::class);

        $rates = ExchangeRate::query()
            ->with('tenant:id,name')
            ->orderByDesc('effective_from')
            ->paginate(30)
            ->through(fn (ExchangeRate $rate): array => [
                'id' => $rate->public_id,
                'pair' => "{$rate->base_currency} → {$rate->quote_currency}",
                'baseCurrency' => $rate->base_currency,
                'quoteCurrency' => $rate->quote_currency,
                'marketRate' => $rate->market_rate,
                'clientRate' => $rate->client_rate,
                'markup' => $rate->markupPercentage().'%',
                'scope' => $rate->tenant->name ?? 'Platform',
                'effectiveFrom' => $rate->effective_from->toIso8601String(),
                'effectiveUntil' => $rate->effective_until?->toIso8601String(),
                'current' => $rate->isCurrent(),
            ]);

        return Inertia::render('Admin/Finance/ExchangeRates', [
            'rates' => $rates,
            'currencies' => Currency::codes(),
            'can' => ['manage' => Gate::allows('manage', ExchangeRate::class)],
        ]);
    }

    public function publishRate(Request $request): RedirectResponse
    {
        Gate::authorize('manage', ExchangeRate::class);

        $validated = $request->validate([
            'base_currency' => ['required', 'string', 'size:3', Rule::in(Currency::codes())],
            'quote_currency' => ['required', 'string', 'size:3', 'different:base_currency', Rule::in(Currency::codes())],
            'market_rate' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,8})?$/'],
            'client_rate' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,8})?$/'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        app(ExchangeRateService::class)->publish(
            base: $validated['base_currency'],
            quote: $validated['quote_currency'],
            marketRate: $validated['market_rate'],
            clientRate: $validated['client_rate'],
            actor: $actor,
            note: $validated['note'] ?? null,
        );

        return back()->with('success', 'New rate published. The previous rate has been closed off.');
    }

    public function pricing(): Response
    {
        Gate::authorize('viewAny', PricingPlan::class);

        $plans = PricingPlan::query()
            ->with(['rules', 'tenant:id,name', 'organization:id,name'])
            ->orderByDesc('is_default')
            ->orderBy('scope')
            ->get()
            ->map(fn (PricingPlan $plan): array => [
                'id' => $plan->public_id,
                'name' => $plan->name,
                'scope' => $plan->scope->value,
                'scopeLabel' => $plan->scope->label(),
                'appliesTo' => $plan->organization->name ?? $plan->tenant->name ?? 'All clients',
                'currency' => $plan->currency,
                'isDefault' => $plan->is_default,
                'isActive' => $plan->is_active,
                'rules' => $plan->rules->map(fn ($rule): array => [
                    'id' => $rule->public_id,
                    'feeType' => $rule->fee_type->value,
                    'feeLabel' => $rule->fee_type->label(),
                    'calculation' => $rule->calculation->value,
                    'value' => $rule->calculation->value === 'PERCENTAGE'
                        ? rtrim(rtrim($rule->percentage, '0'), '.').'%'
                        : Money::ofMinor((int) $rule->fixed_amount, $plan->currency)->format(),
                    'appliesFrom' => $rule->applies_from_amount > 0
                        ? Money::ofMinor($rule->applies_from_amount, $plan->currency)->format()
                        : null,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return Inertia::render('Admin/Finance/Pricing', [
            'plans' => $plans,
            'can' => ['manage' => Gate::allows('manage', PricingPlan::class)],
        ]);
    }

    /**
     * What the platform owes clients, in total, per currency (spec §40).
     *
     * @return list<array<string, string>>
     */
    private function clientLiability(): array
    {
        // Dropped to the base query on purpose: these rows are per-currency
        // totals, not wallets, and hydrating them as models would invite
        // someone to save one back.
        return Wallet::acrossTenants()
            ->selectRaw('currency, SUM(available_balance_cached + reserved_balance_cached) AS held')
            ->groupBy('currency')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'currency' => (string) $row->currency,
                'amount' => Money::ofMinor((int) $row->held, (string) $row->currency)->format(),
            ])
            ->values()
            ->all();
    }
}
