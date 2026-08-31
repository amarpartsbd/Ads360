<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Agency\Actions\AssignAgencyPlan;
use App\Domains\Agency\Actions\ProvisionAgency;
use App\Domains\Agency\Exceptions\AgencyException;
use App\Domains\Billing\Enums\PricingScope;
use App\Domains\Billing\Models\PricingPlan;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\RoleScope;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\TenantType;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use App\Http\Requests\Admin\AssignAgencyPlanRequest;
use App\Http\Requests\Admin\ProvisionAgencyRequest;
use App\Support\Values\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Agencies and resellers, from the platform's side (spec §41, §42).
 *
 * Platform staff are unscoped by design, so everything here reads across
 * tenants deliberately — which is exactly why each method authorizes on a
 * permission first. Nothing on this controller is reachable by an agency user;
 * the whole group sits behind the platform middleware.
 */
final class AgencyController
{
    public function __construct(private readonly AssignAgencyPlan $plans) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ClientsView->value);

        $agencies = Tenant::query()
            ->whereIn('type', [TenantType::Agency, TenantType::Reseller, TenantType::Enterprise])
            ->orderBy('name')
            ->get();

        // Counted in one grouped query rather than per row: an agency list is
        // a page, not an excuse for N queries.
        $clientCounts = Organization::query()
            ->withoutGlobalScopes()
            ->whereIn('tenant_id', $agencies->pluck('id'))
            ->where('is_house_account', false)
            ->selectRaw('tenant_id, count(*) as clients')
            ->groupBy('tenant_id')
            ->pluck('clients', 'tenant_id');

        $planNames = PricingPlan::query()
            ->where('scope', PricingScope::Tenant)
            ->where('is_active', true)
            ->whereIn('tenant_id', $agencies->pluck('id'))
            ->pluck('name', 'tenant_id');

        return Inertia::render('Admin/Agencies/Index', [
            'agencies' => $agencies->map(fn (Tenant $agency): array => [
                'id' => $agency->public_id,
                'name' => $agency->name,
                'type' => $agency->type->value,
                'typeLabel' => $agency->type->label(),
                'status' => $agency->status->value,
                'statusLabel' => $agency->status->label(),
                'currency' => $agency->default_currency,
                'clients' => (int) ($clientCounts[$agency->getKey()] ?? 0),
                // Null means the platform default prices their clients.
                'plan' => $planNames[$agency->getKey()] ?? null,
            ])->values()->all(),
            'moduleEnabled' => (bool) config('platform.features.agency_module'),
            'can' => [
                'provision' => Gate::allows(Permission::ClientsCreate->value),
                'assignPricing' => Gate::allows(Permission::PricingManage->value),
            ],
        ]);
    }

    public function show(string $agency): Response
    {
        Gate::authorize(Permission::ClientsView->value);

        $tenant = $this->requireAgency($agency);

        $organizations = Organization::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->orderByDesc('is_house_account')
            ->orderBy('name')
            ->get();

        $plan = $this->plans->current($tenant);

        return Inertia::render('Admin/Agencies/Show', [
            'agency' => [
                'id' => $tenant->public_id,
                'name' => $tenant->name,
                'type' => $tenant->type->value,
                'typeLabel' => $tenant->type->label(),
                'status' => $tenant->status->value,
                'statusLabel' => $tenant->status->label(),
                'currency' => $tenant->default_currency,
                'billingEmail' => $tenant->billing_email,
            ],
            'clients' => $organizations->map(static fn (Organization $organization): array => [
                'id' => $organization->public_id,
                'name' => $organization->name,
                'status' => $organization->status->value,
                'statusLabel' => $organization->status->label(),
                'isHouseAccount' => $organization->isHouseAccount(),
            ])->values()->all(),
            'staff' => $this->staff($tenant),
            'plan' => $plan === null ? null : $this->serialisePlan($plan),
            'templates' => $this->templates(),
            'can' => ['assignPricing' => Gate::allows(Permission::PricingManage->value)],
        ]);
    }

    public function store(ProvisionAgencyRequest $request, ProvisionAgency $provision): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $result = $provision->handle($request->validated(), $actor);
        } catch (AgencyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.agencies.show', ['agency' => $result['tenant']->public_id])
            ->with('success', "{$result['tenant']->name} provisioned. The owner must verify their email.");
    }

    public function assignPlan(AssignAgencyPlanRequest $request, string $agency): RedirectResponse
    {
        $tenant = $this->requireAgency($agency);

        /** @var PricingPlan|null $template */
        $template = PricingPlan::query()
            ->with('rules')
            ->where('scope', PricingScope::Platform)
            ->where('public_id', $request->validated('plan'))
            ->first();

        if ($template === null) {
            throw new NotFoundHttpException;
        }

        /** @var User $actor */
        $actor = $request->user();

        try {
            $assigned = $this->plans->handle($tenant, $template, $actor);
        } catch (AgencyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "{$tenant->name} is now priced by {$assigned->name}.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function staff(Tenant $tenant): array
    {
        return User::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('is_platform_user', false)
            ->with(['roles' => static fn ($query) => $query->select('roles.id', 'roles.name', 'roles.scope')])
            ->orderBy('name')
            ->get()
            ->map(static fn (User $user): array => [
                'id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status->value,
                // Whether they reach every client, or only the ones they are
                // put on. The distinction is the whole point of §42.
                'reachesEveryClient' => $user->roles
                    ->contains(static fn (Role $role): bool => $role->scope === RoleScope::Tenant),
                'roles' => $user->roles->pluck('name')->unique()->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function templates(): array
    {
        return PricingPlan::query()
            ->with('rules')
            ->where('scope', PricingScope::Platform)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (PricingPlan $plan): array => $this->serialisePlan($plan))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serialisePlan(PricingPlan $plan): array
    {
        return [
            'id' => $plan->public_id,
            'name' => $plan->name,
            'description' => $plan->description,
            'scope' => $plan->scope->value,
            'scopeLabel' => $plan->scope->label(),
            'currency' => $plan->currency,
            'isDefault' => $plan->is_default,
            'rules' => $plan->rules
                ->map(static fn ($rule): array => [
                    'feeLabel' => $rule->fee_type->label(),
                    'value' => $rule->calculation->value === 'PERCENTAGE'
                        ? rtrim(rtrim((string) $rule->percentage, '0'), '.').'%'
                        : Money::ofMinor((int) $rule->fixed_amount, $plan->currency)->format(),
                    'appliesFrom' => (int) $rule->applies_from_amount > 0
                        ? Money::ofMinor((int) $rule->applies_from_amount, $plan->currency)->format()
                        : null,
                ])
                ->values()
                ->all(),
        ];
    }

    private function requireAgency(string $publicId): Tenant
    {
        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()
            ->whereIn('type', [TenantType::Agency, TenantType::Reseller, TenantType::Enterprise])
            ->where('public_id', $publicId)
            ->first();

        if ($tenant === null) {
            throw new NotFoundHttpException;
        }

        return $tenant;
    }
}
