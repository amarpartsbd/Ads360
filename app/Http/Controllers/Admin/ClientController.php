<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Tenant\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Client organizations, across every tenant (spec §41).
 *
 * Listing is server-side paginated: the browser never receives the whole table
 * (spec §71).
 */
final class ClientController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Organization::class);

        $search = trim((string) $request->query('search', ''));

        $organizations = Organization::acrossTenants()
            ->with('tenant:id,name,type')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'ilike', '%'.$search.'%')
                        ->orWhere('contact_email', 'ilike', '%'.$search.'%');
                });
            })
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Organization $organization): array => [
                'id' => $organization->public_id,
                'name' => $organization->name,
                'tenant' => $organization->tenant->name,
                'tenantType' => $organization->tenant->type->value,
                'status' => $organization->status->value,
                'statusLabel' => $organization->status->label(),
                'country' => $organization->country,
                'createdAt' => $organization->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Clients/Index', [
            'organizations' => $organizations,
            'filters' => [
                'search' => $search,
                'status' => $request->query('status'),
            ],
        ]);
    }

    public function show(Organization $organization): Response
    {
        Gate::authorize('view', $organization);

        $organization->load(['tenant:id,name,type,status']);

        return Inertia::render('Admin/Clients/Show', [
            'organization' => [
                'id' => $organization->public_id,
                'name' => $organization->name,
                'legalName' => $organization->legal_name,
                'status' => $organization->status->value,
                'statusLabel' => $organization->status->label(),
                'country' => $organization->country,
                'timezone' => $organization->timezone,
                'currency' => $organization->default_currency,
                'contactEmail' => $organization->contact_email,
                'contactNumber' => $organization->contact_number,
                'website' => $organization->website,
                'createdAt' => $organization->created_at?->toIso8601String(),
                'tenant' => [
                    'name' => $organization->tenant->name,
                    'type' => $organization->tenant->type->value,
                    'status' => $organization->tenant->status->value,
                ],
            ],
            'can' => [
                'verify' => Gate::allows('verify', $organization),
                'suspend' => Gate::allows('suspend', $organization),
            ],
        ]);
    }
}
