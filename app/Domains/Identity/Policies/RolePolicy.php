<?php

declare(strict_types=1);

namespace App\Domains\Identity\Policies;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\RoleScope;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Services\TenantContext;

/**
 * Authorization for roles (spec §7).
 *
 * System roles are read-only to everyone: they define the platform's own
 * vocabulary, and a tenant editing them would change behaviour for every other
 * tenant. Platform-scoped roles are invisible to tenant users entirely, so a
 * client cannot discover — let alone grant themselves — administrative access.
 */
final class RolePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::RolesManage, $this->context->organization());
    }

    public function view(User $user, Role $role): bool
    {
        return $this->isVisibleTo($user, $role)
            && $user->hasPermissionTo(Permission::RolesManage, $this->context->organization());
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permission::RolesManage, $this->context->organization());
    }

    public function update(User $user, Role $role): bool
    {
        if ($role->is_system) {
            return false;
        }

        return $this->view($user, $role);
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->update($user, $role);
    }

    /**
     * Whether a role may be granted to someone. Platform roles are grantable
     * only by platform staff.
     */
    public function assign(User $user, Role $role): bool
    {
        if ($role->scope === RoleScope::Platform && ! $user->isPlatformUser()) {
            return false;
        }

        return $this->isVisibleTo($user, $role)
            && $user->hasPermissionTo(Permission::RolesManage, $this->context->organization());
    }

    private function isVisibleTo(User $user, Role $role): bool
    {
        if ($user->isPlatformUser()) {
            return true;
        }

        if ($role->scope === RoleScope::Platform) {
            return false;
        }

        // Shared system roles, or a role this tenant defined for itself.
        return $role->tenant_id === null || $role->tenant_id === $user->tenant_id;
    }
}
