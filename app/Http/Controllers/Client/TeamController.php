<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Team members of the current organization (spec §82).
 */
final class TeamController
{
    public function index(TenantContext $context): Response
    {
        $organization = $context->requireOrganization();

        Gate::authorize('viewAny', User::class);

        $members = $organization->activeMembers()
            ->with(['roles' => fn ($query) => $query->select('roles.id', 'roles.name', 'roles.slug')])
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status->value,
                'statusLabel' => $user->status->label(),
                'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
                'roles' => $user->roles->pluck('name')->values()->all(),
                'joinedAt' => $user->getRelationValue('pivot')?->joined_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('Client/Team/Index', [
            'organization' => $this->serialise($organization),
            'members' => $members,
            'can' => [
                'manageUsers' => Gate::allows('users.manage'),
                'manageRoles' => Gate::allows('roles.manage'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialise(Organization $organization): array
    {
        return [
            'id' => $organization->public_id,
            'name' => $organization->name,
        ];
    }
}
