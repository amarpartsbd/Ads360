<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Policies;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;

/**
 * Authorization for organizations (spec §5, §68).
 *
 * Every method establishes membership before it looks at permissions. The
 * global tenant scope should already have made another tenant's organization
 * unreachable; this is the second, independent check that makes a scope failure
 * non-exploitable rather than merely unlikely.
 */
final class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformUser()
            ? $user->hasPermissionTo(Permission::ClientsView)
            : $user->reachableOrganizations()->exists();
    }

    public function view(User $user, Organization $organization): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::ClientsView);
        }

        return $this->actsWithin($user, $organization)
            && $user->hasPermissionTo(Permission::ClientsView, $organization);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permission::ClientsCreate);
    }

    public function update(User $user, Organization $organization): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::ClientsUpdate);
        }

        return $this->actsWithin($user, $organization)
            && $user->hasPermissionTo(Permission::ClientsUpdate, $organization);
    }

    public function verify(User $user, Organization $organization): bool
    {
        // Verification is a compliance decision and belongs to platform staff
        // alone; a client can never verify their own business.
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::ClientsVerify);
    }

    public function suspend(User $user, Organization $organization): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::ClientsSuspend);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::ClientsSuspend);
    }

    /**
     * The tenant boundary itself: same tenant, and either an access-granting
     * membership in this specific organization or an agency-wide grant that
     * covers every client of that tenant (spec §42).
     */
    private function actsWithin(User $user, Organization $organization): bool
    {
        return $user->tenant_id !== null
            && $user->tenant_id === $organization->tenant_id
            && $user->canReachOrganization($organization);
    }
}
