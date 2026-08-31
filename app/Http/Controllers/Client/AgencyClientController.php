<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domains\Agency\Actions\AssignAgencyPlan;
use App\Domains\Agency\Actions\AssignStaffToClient;
use App\Domains\Agency\Actions\CreateAgencyClient;
use App\Domains\Agency\DTOs\AgencyClientSummary;
use App\Domains\Agency\Exceptions\AgencyException;
use App\Domains\Agency\Services\AgencyDirectory;
use App\Domains\Billing\Enums\PricingScope;
use App\Domains\Billing\Models\PricingPlan;
use App\Domains\Identity\Enums\RoleScope;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use App\Domains\Tenant\Services\TenantContext;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Requests\Client\AssignAgencyStaffRequest;
use App\Http\Requests\Client\StoreAgencyClientRequest;
use App\Support\Values\Money;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The agency's own clients (spec §42).
 *
 * Every read and every write goes through AgencyDirectory, which resolves an
 * identifier only within the organizations the asking user can reach. An
 * identifier belonging to another agency finds nothing — it is not resolved and
 * then refused, because a refusal confirms the organization exists (spec §5).
 */
final class AgencyClientController
{
    public function __construct(private readonly AgencyDirectory $directory) {}

    public function index(Request $request): Response
    {
        $agency = $this->agency();
        $user = $this->user($request);

        [$since, $until] = $this->window($request);

        $report = $this->directory->report($agency, $user, $since, $until);

        return Inertia::render('Client/Agency/Index', [
            'agency' => ['name' => $agency->name, 'type' => $agency->type->label()],
            'window' => [
                'since' => $since->format('Y-m-d'),
                'until' => $until->format('Y-m-d'),
            ],
            'clients' => $report->clients
                ->map(fn (AgencyClientSummary $client): array => $this->serialise($client))
                ->all(),
            'totals' => [
                'clients' => $report->clientCount,
                'activeCampaigns' => $report->activeCampaigns,
                'impressions' => $report->totalImpressions,
                'clicks' => $report->totalClicks,
                'conversions' => $report->totalConversions,
                'spend' => $report->totalSpend?->jsonSerialize(),
                'balance' => $report->totalBalance?->jsonSerialize(),
                // The screen says why a total is missing rather than showing a
                // sum across currencies that would not be money.
                'currencies' => $report->currencies,
                'spansCurrencies' => $report->spansCurrencies(),
            ],
            // What the agency itself pays, read-only. An agency setting its
            // own fees would be setting what the platform charges it (§36).
            'pricing' => Gate::allows('pricing.view') ? $this->pricing($agency) : null,
            'can' => [
                'createClient' => Gate::allows('clients.create'),
                'manageStaff' => Gate::allows('users.manage'),
            ],
        ]);
    }

    public function store(StoreAgencyClientRequest $request, CreateAgencyClient $create): RedirectResponse
    {
        $agency = $this->agency();

        try {
            $client = $create->handle($agency, $request->validated(), $this->user($request));
        } catch (AgencyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'success',
            "{$client->name} added. They will need business verification before campaigns can run."
        );
    }

    public function show(Request $request, string $client): Response
    {
        $agency = $this->agency();
        $user = $this->user($request);

        $organization = $this->requireClient($agency, $user, $client);

        [$since, $until] = $this->window($request);

        $summary = $this->directory
            ->report($agency, $user, $since, $until)
            ->clients
            ->first(fn (AgencyClientSummary $row): bool => $row->publicId === $organization->public_id);

        return Inertia::render('Client/Agency/Show', [
            'client' => [
                'id' => $organization->public_id,
                'name' => $organization->name,
                'status' => $organization->status->value,
                'statusLabel' => $organization->status->label(),
                'currency' => $organization->default_currency,
                'contactEmail' => $organization->contact_email,
                'website' => $organization->website,
            ],
            'summary' => $summary === null ? null : $this->serialise($summary),
            'window' => ['since' => $since->format('Y-m-d'), 'until' => $until->format('Y-m-d')],
            'assigned' => $this->assignedStaff($organization),
            'assignable' => $this->assignableStaff($agency, $organization),
            'roles' => array_map(
                static fn (string $slug): array => [
                    'slug' => $slug,
                    'label' => $slug === 'agency-manager' ? 'Manager' : 'Staff',
                ],
                AssignStaffToClient::ASSIGNABLE_ROLES,
            ),
            'can' => ['manageStaff' => Gate::allows('users.manage')],
        ]);
    }

    /**
     * Open a client's workspace.
     *
     * Deliberately the same session key the workspace switcher uses, so an
     * agency working inside a client is in exactly the state a client user
     * would be — same policies, same context, same audit organization. There is
     * no second, weaker path into a client's data.
     */
    public function open(Request $request, string $client): RedirectResponse
    {
        $agency = $this->agency();
        $organization = $this->requireClient($agency, $this->user($request), $client);

        $request->session()->put(ResolveTenantContext::SESSION_KEY, $organization->getKey());

        return redirect()
            ->route('client.dashboard')
            ->with('success', "Working in {$organization->name}.");
    }

    public function assignStaff(
        AssignAgencyStaffRequest $request,
        string $client,
        AssignStaffToClient $assign,
    ): RedirectResponse {
        $agency = $this->agency();
        $actor = $this->user($request);

        $organization = $this->requireClient($agency, $actor, $client);
        $staff = $this->requireStaff($agency, (string) $request->validated('user'));

        try {
            $assign->handle($agency, $organization, $staff, (string) $request->validated('role'), $actor);
        } catch (AgencyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "{$staff->name} now works on {$organization->name}.");
    }

    public function removeStaff(
        Request $request,
        string $client,
        string $member,
        AssignStaffToClient $assign,
    ): RedirectResponse {
        $agency = $this->agency();
        $actor = $this->user($request);

        Gate::authorize('users.manage');

        $organization = $this->requireClient($agency, $actor, $client);
        $staff = $this->requireStaff($agency, $member);

        try {
            $assign->remove($agency, $organization, $staff, $actor);
        } catch (AgencyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "{$staff->name} no longer works on {$organization->name}.");
    }

    /**
     * The fee schedule that prices this agency's clients (spec §36, §42).
     *
     * An agency needs its own rate to quote its clients, so it can read this;
     * it cannot change it, because these are the platform's fees rather than
     * the agency's. An agency with no schedule of its own is on the platform
     * default, and is told so rather than shown an empty panel.
     *
     * @return array<string, mixed>
     */
    private function pricing(Tenant $agency): array
    {
        $plan = app(AssignAgencyPlan::class)->current($agency)
            ?? PricingPlan::query()
                ->with('rules')
                ->where('scope', PricingScope::Platform)
                ->where('is_default', true)
                ->where('is_active', true)
                ->first();

        if ($plan === null) {
            return ['name' => null, 'isAgencyRate' => false, 'rules' => []];
        }

        return [
            'name' => $plan->name,
            'isAgencyRate' => $plan->scope === PricingScope::Tenant,
            'currency' => $plan->currency,
            'rules' => $plan->rules
                ->where('is_active', true)
                ->sortBy('priority')
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

    /**
     * @return array<string, mixed>
     */
    private function serialise(AgencyClientSummary $client): array
    {
        return [
            'id' => $client->publicId,
            'name' => $client->name,
            'status' => $client->status->value,
            'statusLabel' => $client->status->label(),
            'verified' => $client->isVerified,
            'canSpend' => $client->canSpend(),
            'balance' => $client->availableBalance->jsonSerialize(),
            // Null travels as null: a client with nothing reported is shown as
            // such rather than as a client that spent nothing (§87).
            'spend' => $client->spend?->jsonSerialize(),
            'impressions' => $client->impressions,
            'clicks' => $client->clicks,
            'conversions' => $client->conversions,
            'activeCampaigns' => $client->activeCampaigns,
            'totalCampaigns' => $client->totalCampaigns,
            'assignedStaff' => $client->assignedStaff,
        ];
    }

    /**
     * People already working on this client.
     *
     * @return list<array<string, mixed>>
     */
    private function assignedStaff(Organization $client): array
    {
        return $client->activeMembers()
            ->get()
            ->map(static fn (User $member): array => [
                'id' => $member->public_id,
                'name' => $member->name,
                'email' => $member->email,
            ])
            ->values()
            ->all();
    }

    /**
     * Agency people who could be put on this client.
     *
     * Owners and admins are absent on purpose: their grant already reaches
     * every client, so offering to "assign" them would suggest their access
     * depends on it.
     *
     * @return list<array<string, mixed>>
     */
    private function assignableStaff(Tenant $agency, Organization $client): array
    {
        $assigned = $client->activeMembers()->pluck('users.id')->all();

        return User::query()
            ->where('tenant_id', $agency->getKey())
            ->where('is_platform_user', false)
            ->whereNotIn('id', $assigned)
            ->whereDoesntHave('roles', static function ($query) use ($agency): void {
                $query->where('roles.scope', RoleScope::Tenant->value)
                    ->whereNull('role_user.organization_id')
                    ->where('role_user.tenant_id', $agency->getKey());
            })
            ->orderBy('name')
            ->get()
            ->map(static fn (User $member): array => [
                'id' => $member->public_id,
                'name' => $member->name,
                'email' => $member->email,
            ])
            ->values()
            ->all();
    }

    private function requireClient(Tenant $agency, User $user, string $publicId): Organization
    {
        $organization = $this->directory->clientFor($agency, $user, $publicId);

        if ($organization === null) {
            // Not a 403: a refusal would confirm the organization exists.
            throw new NotFoundHttpException;
        }

        return $organization;
    }

    private function requireStaff(Tenant $agency, string $publicId): User
    {
        /** @var User|null $staff */
        $staff = User::query()
            ->where('tenant_id', $agency->getKey())
            ->where('is_platform_user', false)
            ->where('public_id', $publicId)
            ->first();

        if ($staff === null) {
            throw new NotFoundHttpException;
        }

        return $staff;
    }

    private function agency(): Tenant
    {
        $tenant = app(TenantContext::class)->requireTenant();

        if (! $tenant->type->managesClients() || ! config('platform.features.agency_module')) {
            throw new NotFoundHttpException;
        }

        return $tenant;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * The reporting window, defaulting to the last thirty days.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function window(Request $request): array
    {
        $validated = $request->validate([
            'since' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $until = isset($validated['until'])
            ? Carbon::parse($validated['until'])
            : Carbon::today();

        $since = isset($validated['since'])
            ? Carbon::parse($validated['since'])
            : $until->copy()->subDays(29);

        if ($since->greaterThan($until)) {
            [$since, $until] = [$until, $since];
        }

        return [$since->toDateTimeImmutable(), $until->toDateTimeImmutable()];
    }
}
