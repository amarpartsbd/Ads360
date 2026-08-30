<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\InvitationStatus;
use App\Domains\Tenant\Enums\MembershipStatus;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\OrganizationInvitation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes to an existing member's standing in an organization (spec §82).
 *
 * Every one of these is a permission change and every one is audited. Two
 * invariants run through all of them: an organization can never be left with no
 * one who can administer it, and an actor cannot act on themselves in a way
 * that would lock them out or elevate them.
 */
final class ManageTeamMember
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Replaces the member's roles within this organization.
     *
     * Roles granted in other organizations, and tenant-wide grants, are left
     * alone: this is a change to what someone may do *here*.
     *
     * @param  list<Role>  $roles
     *
     * @throws ValidationException
     */
    public function changeRoles(Organization $organization, User $member, array $roles, User $actor): void
    {
        if ($roles === []) {
            throw ValidationException::withMessages([
                'roles' => 'A member must hold at least one role.',
            ]);
        }

        foreach ($roles as $role) {
            if (! $actor->can('assign', $role)) {
                throw ValidationException::withMessages([
                    'roles' => 'You cannot grant one of the selected roles.',
                ]);
            }
        }

        DB::transaction(function () use ($organization, $member, $roles, $actor): void {
            $previous = $this->roleSlugsIn($organization, $member);

            $this->guardLastAdministrator($organization, $member, $roles);

            $member->roles()
                ->wherePivot('organization_id', $organization->getKey())
                ->detach();

            foreach ($roles as $role) {
                $member->roles()->attach($role->getKey(), [
                    'organization_id' => $organization->getKey(),
                    'tenant_id' => $organization->tenant_id,
                    'granted_by' => $actor->getKey(),
                ]);
            }

            $member->forgetCachedPermissions();

            $this->audit->record(
                action: AuditAction::RoleAssigned,
                resource: $member,
                before: ['roles' => $previous],
                after: ['roles' => array_map(static fn (Role $role): string => $role->slug, $roles)],
                organization: $organization,
                actor: $actor,
            );
        });
    }

    /**
     * Suspends a membership without removing it, so access stops immediately
     * but the person's history in the organization is preserved.
     */
    public function suspend(Organization $organization, User $member, User $actor): void
    {
        $this->guardNotSelf($member, $actor, 'suspend');

        DB::transaction(function () use ($organization, $member, $actor): void {
            $this->guardLastAdministrator($organization, $member, []);

            $organization->members()->updateExistingPivot($member->getKey(), [
                'status' => MembershipStatus::Suspended->value,
            ]);

            $this->audit->record(
                action: AuditAction::MembershipUpdated,
                resource: $member,
                after: ['status' => MembershipStatus::Suspended->value],
                organization: $organization,
                actor: $actor,
            );
        });
    }

    public function reinstate(Organization $organization, User $member, User $actor): void
    {
        DB::transaction(function () use ($organization, $member, $actor): void {
            $organization->members()->updateExistingPivot($member->getKey(), [
                'status' => MembershipStatus::Active->value,
            ]);

            $this->audit->record(
                action: AuditAction::MembershipUpdated,
                resource: $member,
                after: ['status' => MembershipStatus::Active->value],
                organization: $organization,
                actor: $actor,
            );
        });
    }

    /**
     * Removes someone from the organization: membership detached and every role
     * they held here revoked. The user account itself is untouched — they may
     * still belong to other organizations in the tenant.
     */
    public function remove(Organization $organization, User $member, User $actor): void
    {
        $this->guardNotSelf($member, $actor, 'remove');

        DB::transaction(function () use ($organization, $member, $actor): void {
            $this->guardLastAdministrator($organization, $member, []);

            $previous = $this->roleSlugsIn($organization, $member);

            $member->roles()
                ->wherePivot('organization_id', $organization->getKey())
                ->detach();

            $organization->members()->detach($member->getKey());
            $member->forgetCachedPermissions();

            $this->audit->record(
                action: AuditAction::MembershipRevoked,
                resource: $member,
                before: ['roles' => $previous],
                organization: $organization,
                actor: $actor,
            );
        });
    }

    public function revokeInvitation(OrganizationInvitation $invitation, User $actor): void
    {
        if (! $invitation->status->isOpen()) {
            throw ValidationException::withMessages([
                'invitation' => 'That invitation is no longer pending.',
            ]);
        }

        $organization = $invitation->organization()->firstOrFail();

        $invitation->forceFill([
            'status' => InvitationStatus::Revoked,
            'revoked_at' => Carbon::now(),
            // Spend the token so a mail already delivered cannot be redeemed.
            'token_hash' => hash('sha256', 'revoked:'.$invitation->public_id),
        ])->save();

        $this->audit->record(
            action: AuditAction::InvitationRevoked,
            resource: $invitation,
            context: ['email' => $invitation->email],
            organization: $organization,
            actor: $actor,
        );
    }

    /**
     * Refuses a change that would leave the organization with nobody able to
     * manage its team. Locking every administrator out of an organization is
     * not recoverable by the client — it becomes a support case.
     *
     * @param  list<Role>  $replacementRoles  roles the member will hold after the change
     */
    private function guardLastAdministrator(Organization $organization, User $member, array $replacementRoles): void
    {
        $keepsAdministration = false;

        foreach ($replacementRoles as $role) {
            if ($role->permissions()->where('name', 'users.manage')->exists()) {
                $keepsAdministration = true;

                break;
            }
        }

        if ($keepsAdministration) {
            return;
        }

        $memberAdministers = $member->hasPermissionTo('users.manage', $organization);

        if (! $memberAdministers) {
            return;
        }

        $otherAdministrators = $organization->activeMembers()
            ->where('users.id', '!=', $member->getKey())
            ->get()
            ->filter(fn (User $other): bool => $other->hasPermissionTo('users.manage', $organization))
            ->count();

        if ($otherAdministrators === 0) {
            throw ValidationException::withMessages([
                'member' => 'This is the only member who can manage the team. Give someone else that role first.',
            ]);
        }
    }

    private function guardNotSelf(User $member, User $actor, string $verb): void
    {
        if ($member->is($actor)) {
            throw ValidationException::withMessages([
                'member' => "You cannot {$verb} your own membership.",
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function roleSlugsIn(Organization $organization, User $member): array
    {
        return $member->roles()
            ->wherePivot('organization_id', $organization->getKey())
            ->pluck('roles.slug')
            ->all();
    }
}
