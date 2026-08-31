<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domains\Identity\Actions\InviteTeamMember;
use App\Domains\Identity\Actions\ManageTeamMember;
use App\Domains\Identity\Enums\RoleScope;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\InvitationStatus;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\OrganizationInvitation;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Team members and invitations for the current organization (spec §82).
 */
final class TeamController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        $organization = $this->context->requireOrganization();

        Gate::authorize('viewAny', User::class);

        return Inertia::render('Client/Team/Index', [
            'organization' => ['id' => $organization->public_id, 'name' => $organization->name],
            'members' => $this->members($organization),
            'invitations' => $this->pendingInvitations($organization),
            'assignableRoles' => $this->assignableRoles(),
            'can' => [
                'manageUsers' => Gate::allows('users.manage'),
                'manageRoles' => Gate::allows('roles.manage'),
            ],
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $organization = $this->context->requireOrganization();

        Gate::authorize('create', User::class);

        $validated = $request->validate([
            'email' => ['required', 'email:rfc,strict', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'role' => ['required', 'string', 'size:26'],
        ]);

        $role = $this->resolveAssignableRole($validated['role']);

        /** @var User $inviter */
        $inviter = $request->user();

        app(InviteTeamMember::class)->handle(
            $organization,
            $inviter,
            $validated['email'],
            $role,
            $validated['name'] ?? null,
        );

        return back()->with('success', "Invitation sent to {$validated['email']}.");
    }

    public function resendInvitation(Request $request, OrganizationInvitation $invitation): RedirectResponse
    {
        $this->authorizeInvitation($invitation);

        /** @var User $actor */
        $actor = $request->user();

        app(InviteTeamMember::class)->resend($invitation, $actor);

        return back()->with('success', 'Invitation sent again.');
    }

    public function revokeInvitation(Request $request, OrganizationInvitation $invitation): RedirectResponse
    {
        $this->authorizeInvitation($invitation);

        /** @var User $actor */
        $actor = $request->user();

        app(ManageTeamMember::class)->revokeInvitation($invitation, $actor);

        return back()->with('success', 'Invitation revoked.');
    }

    public function updateRoles(Request $request, User $member): RedirectResponse
    {
        $organization = $this->context->requireOrganization();

        Gate::authorize('manageRoles', $member);
        $this->assertMemberOf($organization, $member);

        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'size:26'],
        ]);

        $roles = array_map(fn (string $id): Role => $this->resolveAssignableRole($id), $validated['roles']);

        /** @var User $actor */
        $actor = $request->user();

        app(ManageTeamMember::class)->changeRoles($organization, $member, $roles, $actor);

        return back()->with('success', "Updated roles for {$member->name}.");
    }

    public function suspend(Request $request, User $member): RedirectResponse
    {
        $organization = $this->context->requireOrganization();

        Gate::authorize('suspend', $member);
        $this->assertMemberOf($organization, $member);

        /** @var User $actor */
        $actor = $request->user();

        app(ManageTeamMember::class)->suspend($organization, $member, $actor);

        return back()->with('success', "{$member->name} has been suspended.");
    }

    public function reinstate(Request $request, User $member): RedirectResponse
    {
        $organization = $this->context->requireOrganization();

        Gate::authorize('update', $member);
        $this->assertMemberOf($organization, $member, requireActive: false);

        /** @var User $actor */
        $actor = $request->user();

        app(ManageTeamMember::class)->reinstate($organization, $member, $actor);

        return back()->with('success', "{$member->name} has been reinstated.");
    }

    public function remove(Request $request, User $member): RedirectResponse
    {
        $organization = $this->context->requireOrganization();

        Gate::authorize('delete', $member);
        $this->assertMemberOf($organization, $member, requireActive: false);

        /** @var User $actor */
        $actor = $request->user();

        app(ManageTeamMember::class)->remove($organization, $member, $actor);

        return back()->with('success', "{$member->name} has been removed from this organization.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function members(Organization $organization): array
    {
        return $organization->members()
            ->with(['roles' => fn ($query) => $query->wherePivot('organization_id', $organization->getKey())])
            ->get()
            ->map(function (User $user): array {
                $pivot = $user->getRelationValue('pivot');

                return [
                    'id' => $user->public_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'membershipStatus' => $pivot?->status->value,
                    'membershipStatusLabel' => $pivot?->status->label(),
                    'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
                    'roles' => $user->roles
                        ->map(fn (Role $role): array => ['id' => $role->public_id, 'name' => $role->name])
                        ->values()
                        ->all(),
                    'joinedAt' => $pivot?->joined_at?->toIso8601String(),
                    'isSelf' => $user->is(request()->user()),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingInvitations(Organization $organization): array
    {
        return OrganizationInvitation::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', InvitationStatus::Pending)
            ->with('role:id,public_id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (OrganizationInvitation $invitation): array => [
                'id' => $invitation->public_id,
                'email' => $invitation->email,
                'name' => $invitation->name,
                'role' => $invitation->role->name,
                'expiresAt' => $invitation->expires_at->toIso8601String(),
                'expired' => $invitation->hasExpired(),
                'lastSentAt' => $invitation->last_sent_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Roles the signed-in user may actually grant. Anything they cannot assign
     * is not offered, so the form cannot present an escalation the server would
     * then reject.
     *
     * @return list<array<string, string>>
     */
    private function assignableRoles(): array
    {
        /** @var User $user */
        $user = request()->user();

        return Role::query()
            ->where('scope', RoleScope::Organization)
            ->where(function ($query) use ($user): void {
                $query->whereNull('tenant_id')->orWhere('tenant_id', $user->tenant_id);
            })
            ->orderBy('name')
            ->get()
            ->filter(fn (Role $role): bool => $user->can('assign', $role))
            ->map(fn (Role $role): array => [
                'id' => $role->public_id,
                'name' => $role->name,
                'description' => (string) $role->description,
            ])
            ->values()
            ->all();
    }

    /**
     * Resolves a role by public id, restricted to the ones the actor may grant.
     * An identifier for any other role simply does not resolve.
     */
    private function resolveAssignableRole(string $publicId): Role
    {
        /** @var User $user */
        $user = request()->user();

        /** @var Role|null $role */
        $role = Role::query()
            ->where('public_id', $publicId)
            ->where('scope', RoleScope::Organization)
            ->where(function ($query) use ($user): void {
                $query->whereNull('tenant_id')->orWhere('tenant_id', $user->tenant_id);
            })
            ->first();

        abort_if($role === null || ! $user->can('assign', $role), 403, 'You cannot grant that role.');

        return $role;
    }

    /**
     * The target must belong to the organization currently in context, so a
     * member identifier from elsewhere cannot be acted on.
     */
    private function assertMemberOf(Organization $organization, User $member, bool $requireActive = true): void
    {
        $membership = $requireActive
            ? $organization->activeMembers()
            : $organization->members();

        abort_unless($membership->where('users.id', $member->getKey())->exists(), 404);
    }

    private function authorizeInvitation(OrganizationInvitation $invitation): void
    {
        $organization = $this->context->requireOrganization();

        Gate::authorize('create', User::class);

        abort_unless($invitation->organization_id === $organization->getKey(), 404);
    }
}
