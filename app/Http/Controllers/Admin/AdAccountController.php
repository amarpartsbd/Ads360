<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Advertising\Actions\ChangeAdAccountStatus;
use App\Domains\Advertising\Actions\RegisterAdAccount;
use App\Domains\Advertising\Actions\UpdateAdAccount;
use App\Domains\Advertising\Enums\AdAccountBillingStatus;
use App\Domains\Advertising\Enums\AdAccountHealth;
use App\Domains\Advertising\Enums\AdAccountStatus;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Exceptions\AdAccountException;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Models\AdAccountPool;
use App\Domains\Advertising\Services\AdAccountHealthService;
use App\Http\Requests\Admin\StoreAdAccountRequest;
use App\Http\Requests\Admin\UpdateAdAccountRequest;
use App\Support\Values\Currency;
use App\Support\Values\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The managed ad account inventory, from the operator's side (spec §17, §20).
 *
 * Every amount that reaches a prop is already formatted by Money. The browser
 * is given strings to display, not numbers to do arithmetic on (Rule 8).
 */
final class AdAccountController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AdAccount::class);

        $accounts = AdAccount::query()
            ->when(
                $request->filled('provider'),
                fn ($query) => $query->where('provider', strtoupper($request->string('provider')->toString())),
            )
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', strtoupper($request->string('status')->toString())),
            )
            ->when(
                $request->boolean('attention'),
                fn ($query) => $query->needingAttention(),
            )
            ->orderByDesc('allocation_priority')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/AdAccounts/Index', [
            'accounts' => $accounts->through(fn (AdAccount $account): array => $this->summarise($account)),
            'filters' => [
                'provider' => $request->string('provider')->toString() ?: null,
                'status' => $request->string('status')->toString() ?: null,
                'attention' => $request->boolean('attention'),
            ],
            'options' => $this->options(),
            'can' => [
                'create' => Gate::allows('create', AdAccount::class),
            ],
        ]);
    }

    public function show(AdAccount $adAccount): Response
    {
        Gate::authorize('view', $adAccount);

        $adAccount->loadMissing('pools');

        return Inertia::render('Admin/AdAccounts/Show', [
            'account' => [
                ...$this->summarise($adAccount),
                'timezone' => $adAccount->timezone,
                'lastError' => $adAccount->last_error,
                'consecutiveFailures' => $adAccount->consecutive_failures,
                'disabledReason' => $adAccount->disabled_reason,
                'riskScore' => $adAccount->risk_score,
                'allocationPriority' => $adAccount->allocation_priority,
                'pools' => $adAccount->pools
                    ->map(static fn (AdAccountPool $pool): array => [
                        'id' => $pool->public_id,
                        'name' => $pool->name,
                        'status' => $pool->status->value,
                    ])
                    ->values()
                    ->all(),
                'allowedTransitions' => array_map(
                    static fn (AdAccountStatus $status): array => [
                        'value' => $status->value,
                        'label' => $status->label(),
                    ],
                    $adAccount->status->allowedTransitions(),
                ),
            ],
            'can' => [
                'update' => Gate::allows('update', $adAccount),
                'manageHealth' => Gate::allows('manageHealth', $adAccount),
            ],
        ]);
    }

    public function store(StoreAdAccountRequest $request, RegisterAdAccount $register): RedirectResponse
    {
        $validated = $request->validated();
        $currency = Currency::of($validated['currency']);

        try {
            $account = $register->handle(
                provider: Provider::from($validated['provider']),
                externalAccountId: $validated['external_account_id'],
                name: $validated['name'],
                currency: $currency->code,
                timezone: $validated['timezone'],
                actor: $request->user(),
                // Converted server-side: the browser sends what the operator
                // typed, and the minor-unit figure is derived here (Rule 8).
                dailySpendLimitMinor: $this->toMinor($validated['daily_spend_limit'] ?? null, $currency),
                monthlySpendLimitMinor: $this->toMinor($validated['monthly_spend_limit'] ?? null, $currency),
            );
        } catch (AdAccountException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.ad-accounts.show', $account)
            ->with('success', 'The account is registered. Confirm its billing and limits before activating it.');
    }

    public function update(
        UpdateAdAccountRequest $request,
        AdAccount $adAccount,
        UpdateAdAccount $update,
    ): RedirectResponse {
        $validated = $request->validated();
        $currency = $adAccount->currency();
        $changes = [];

        foreach (['name', 'timezone', 'risk_score', 'allocation_priority'] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $validated[$field];
            }
        }

        foreach (['daily_spend_limit', 'monthly_spend_limit'] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $this->toMinor($validated[$field], $currency);
            }
        }

        try {
            $update->handle($adAccount, $changes, $request->user());
        } catch (AdAccountException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'The account has been updated.');
    }

    public function changeStatus(
        Request $request,
        AdAccount $adAccount,
        ChangeAdAccountStatus $change,
    ): RedirectResponse {
        Gate::authorize('update', $adAccount);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(AdAccountStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $change->handle(
                $adAccount,
                AdAccountStatus::from($validated['status']),
                $request->user(),
                $validated['reason'] ?? null,
            );
        } catch (AdAccountException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'The account status has been changed.');
    }

    /**
     * Ask the provider now rather than waiting for the hourly sweep.
     *
     * Synchronous because an operator is watching, and it is one request.
     */
    public function refreshHealth(AdAccount $adAccount, AdAccountHealthService $health): RedirectResponse
    {
        Gate::authorize('manageHealth', $adAccount);

        $result = $health->check($adAccount);

        return back()->with('success', "The provider reports this account as {$result->label()}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(AdAccount $account): array
    {
        return [
            ...$account->describe(),
            'providerLabel' => $account->provider->label(),
            'statusLabel' => $account->status->label(),
            'healthLabel' => $account->health_status->label(),
            'billingLabel' => $account->billing_status->label(),
            'needsAttention' => $account->needsAttention(),
            'allocatable' => $account->isAllocatable(),
            'dailySpendLimit' => $account->dailySpendLimit()?->format(),
            'monthlySpendLimit' => $account->monthlySpendLimit()?->format(),
            'currentDailySpend' => $account->currentDailySpend()->format(),
            'currentMonthlySpend' => $account->currentMonthlySpend()->format(),
            'committedAmount' => $account->committedAmount()->format(),
            'dailyHeadroom' => $account->dailyHeadroom()?->format(),
            'dailyUtilisation' => $account->dailyUtilisationPercent(),
            'lastSyncedAt' => $account->last_synced_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function options(): array
    {
        return [
            'providers' => array_map(
                static fn (Provider $provider): array => [
                    'value' => $provider->value,
                    'label' => $provider->label(),
                ],
                Provider::cases(),
            ),
            'statuses' => array_map(
                static fn (AdAccountStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                AdAccountStatus::cases(),
            ),
            'health' => array_map(
                static fn (AdAccountHealth $health): array => [
                    'value' => $health->value,
                    'label' => $health->label(),
                ],
                AdAccountHealth::cases(),
            ),
            'billing' => array_map(
                static fn (AdAccountBillingStatus $billing): array => [
                    'value' => $billing->value,
                    'label' => $billing->label(),
                ],
                AdAccountBillingStatus::cases(),
            ),
            'currencies' => array_map(
                static fn (string $code): array => ['value' => $code, 'label' => $code],
                Currency::codes(),
            ),
        ];
    }

    private function toMinor(mixed $amount, Currency $currency): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return Money::of((string) $amount, $currency)->minorUnits;
    }
}
