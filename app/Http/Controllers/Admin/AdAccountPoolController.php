<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Advertising\Actions\ManagePoolMembership;
use App\Domains\Advertising\Actions\SaveAdAccountPool;
use App\Domains\Advertising\Enums\PoolStatus;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\SelectionStrategy;
use App\Domains\Advertising\Exceptions\AdAccountException;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Models\AdAccountPool;
use App\Domains\Advertising\Services\PoolEligibilityService;
use App\Domains\Advertising\Values\AllocationRules;
use App\Http\Requests\Admin\StoreAdAccountPoolRequest;
use App\Http\Requests\Admin\UpdateAdAccountPoolRequest;
use App\Support\Values\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Ad account pools and their allocation rules (spec §18, §19).
 */
final class AdAccountPoolController
{
    public function __construct(private readonly PoolEligibilityService $eligibility) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', AdAccountPool::class);

        $pools = AdAccountPool::query()
            ->withCount('members')
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/AdAccounts/Pools/Index', [
            'pools' => $pools
                ->map(fn (AdAccountPool $pool): array => $this->summarise($pool))
                ->values()
                ->all(),
            'options' => $this->options(),
            'can' => [
                'create' => Gate::allows('create', AdAccountPool::class),
            ],
        ]);
    }

    public function show(AdAccountPool $adAccountPool): Response
    {
        Gate::authorize('view', $adAccountPool);

        $adAccountPool->loadMissing('accounts');

        return Inertia::render('Admin/AdAccounts/Pools/Show', [
            'pool' => [
                ...$this->summarise($adAccountPool),
                'description' => $adAccountPool->description,
                'rules' => $adAccountPool->allocation_rules,
                'members' => $adAccountPool->accounts
                    ->map(function (AdAccount $account) use ($adAccountPool): array {
                        // The reasons an account is currently unusable are the
                        // whole point of this screen: an empty pool with no
                        // explanation is the failure mode to avoid.
                        $failures = $this->eligibility->accountFailures($adAccountPool, $account);

                        return [
                            'id' => $account->public_id,
                            'name' => $account->name,
                            'status' => $account->status->label(),
                            'health' => $account->health_status->label(),
                            'weight' => (int) $account->pivot->weight,
                            'utilisation' => $account->dailyUtilisationPercent(),
                            'blockedBy' => $failures,
                        ];
                    })
                    ->values()
                    ->all(),
                'allowedTransitions' => array_map(
                    static fn (PoolStatus $status): array => [
                        'value' => $status->value,
                        'label' => $status->label(),
                    ],
                    $adAccountPool->status->allowedTransitions(),
                ),
            ],
            'candidates' => AdAccount::query()
                ->forProvider($adAccountPool->provider)
                ->where('currency', $adAccountPool->currency)
                ->whereNotIn('id', $adAccountPool->accounts->modelKeys())
                ->orderBy('name')
                ->get()
                ->map(static fn (AdAccount $account): array => [
                    'id' => $account->public_id,
                    'name' => $account->name,
                    'health' => $account->health_status->label(),
                ])
                ->values()
                ->all(),
            'options' => $this->options(),
            'can' => [
                'update' => Gate::allows('update', $adAccountPool),
                'manageMembers' => Gate::allows('manageMembers', $adAccountPool),
            ],
        ]);
    }

    public function store(StoreAdAccountPoolRequest $request, SaveAdAccountPool $save): RedirectResponse
    {
        $validated = $request->validated();

        $pool = $save->create(
            name: $validated['name'],
            provider: Provider::from($validated['provider']),
            currency: Currency::of($validated['currency'])->code,
            strategy: SelectionStrategy::from($validated['selection_strategy']),
            rules: $this->rulesFrom($validated['rules'] ?? []),
            actor: $request->user(),
            description: $validated['description'] ?? null,
            priority: $validated['priority'] ?? 50,
        );

        return redirect()
            ->route('admin.ad-account-pools.show', $pool)
            ->with('success', 'The pool has been created as a draft. Add accounts, then activate it.');
    }

    public function update(
        UpdateAdAccountPoolRequest $request,
        AdAccountPool $adAccountPool,
        SaveAdAccountPool $save,
    ): RedirectResponse {
        Gate::authorize('update', $adAccountPool);

        $validated = $request->validated();

        try {
            $save->update(
                pool: $adAccountPool,
                actor: $request->user(),
                name: $validated['name'] ?? null,
                description: $validated['description'] ?? null,
                strategy: isset($validated['selection_strategy'])
                    ? SelectionStrategy::from($validated['selection_strategy'])
                    : null,
                rules: isset($validated['rules']) ? $this->rulesFrom($validated['rules']) : null,
                priority: $validated['priority'] ?? null,
            );
        } catch (AdAccountException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'The pool has been updated.');
    }

    public function changeStatus(
        Request $request,
        AdAccountPool $adAccountPool,
        SaveAdAccountPool $save,
    ): RedirectResponse {
        Gate::authorize('update', $adAccountPool);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(PoolStatus::class)],
        ]);

        try {
            $save->changeStatus($adAccountPool, PoolStatus::from($validated['status']), $request->user());
        } catch (AdAccountException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'The pool status has been changed.');
    }

    public function addMember(
        Request $request,
        AdAccountPool $adAccountPool,
        ManagePoolMembership $membership,
    ): RedirectResponse {
        Gate::authorize('manageMembers', $adAccountPool);

        $validated = $request->validate([
            'account' => ['required', 'string', 'size:26'],
            'weight' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        $account = AdAccount::query()->where('public_id', $validated['account'])->firstOrFail();

        try {
            $membership->add($adAccountPool, $account, $request->user(), $validated['weight'] ?? 1);
        } catch (AdAccountException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'The account has been added to the pool.');
    }

    public function removeMember(
        Request $request,
        AdAccountPool $adAccountPool,
        AdAccount $adAccount,
        ManagePoolMembership $membership,
    ): RedirectResponse {
        Gate::authorize('manageMembers', $adAccountPool);

        try {
            $membership->remove($adAccountPool, $adAccount, $request->user());
        } catch (AdAccountException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'The account has been removed from the pool.');
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function rulesFrom(array $rules): AllocationRules
    {
        try {
            return AllocationRules::fromArray($rules);
        } catch (InvalidArgumentException $exception) {
            // The value object is the authority on what a rule may say; a
            // refusal from it belongs on the field, not on an error page.
            throw ValidationException::withMessages(['rules' => $exception->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(AdAccountPool $pool): array
    {
        return [
            'id' => $pool->public_id,
            'name' => $pool->name,
            'slug' => $pool->slug,
            'provider' => $pool->provider->value,
            'providerLabel' => $pool->provider->label(),
            'currency' => $pool->currency,
            'status' => $pool->status->value,
            'statusLabel' => $pool->status->label(),
            'strategy' => $pool->selection_strategy->value,
            'strategyLabel' => $pool->selection_strategy->label(),
            'strategyDescription' => $pool->selection_strategy->description(),
            'priority' => $pool->priority,
            'memberCount' => $pool->members_count ?? $pool->members()->count(),
            'allocatable' => $pool->isAllocatable(),
        ];
    }

    /**
     * @return array<string, mixed>
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
            'strategies' => array_map(
                static fn (SelectionStrategy $strategy): array => [
                    'value' => $strategy->value,
                    'label' => $strategy->label(),
                    'description' => $strategy->description(),
                    'usesWeight' => $strategy->usesWeight(),
                ],
                SelectionStrategy::cases(),
            ),
            'statuses' => array_map(
                static fn (PoolStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                PoolStatus::cases(),
            ),
            'currencies' => array_map(
                static fn (string $code): array => ['value' => $code, 'label' => $code],
                Currency::codes(),
            ),
        ];
    }
}
