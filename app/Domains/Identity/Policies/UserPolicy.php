<?php

declare(strict_types=1);

namespace App\Domains\Identity\Policies;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Services\TenantContext;

/**
 * Authorization for managing other users (spec §82).
 *
 * Two rules run through all of it: a tenant user can only ever see or touch
 * users inside their own tenant, and nobody may escalate or suspend themselves.
 */
final class UserPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::UsersManage, $this->context->organization());
    }

    public function view(User $user, User $target): bool
    {
        if ($user->is($target)) {
            return true;
        }

        return $this->sharesBoundary($user, $target)
            && $user->hasPermissionTo(Permission::UsersManage, $this->context->organization());
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permission::UsersManage, $this->context->organization());
    }

    public function update(User $user, User $target): bool
    {
        return $this->sharesBoundary($user, $target)
            && $user->hasPermissionTo(Permission::UsersManage, $this->context->organization());
    }

    public function suspend(User $user, User $target): bool
    {
        // Suspending yourself would lock the account that is meant to be able
        // to undo the mistake.
        if ($user->is($target)) {
            return false;
        }

        return $this->update($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        return $this->suspend($user, $target);
    }

    /**
     * Changing what a user is allowed to do is a privileged action, separate
     * from ordinary user management, and never permitted on oneself (spec §7).
     */
    public function manageRoles(User $user, User $target): bool
    {
        if ($user->is($target)) {
            return false;
        }

        return $this->sharesBoundary($user, $target)
            && $user->hasPermissionTo(Permission::RolesManage, $this->context->organization());
    }

    /**
     * Platform staff manage platform staff; tenant users manage users of their
     * own tenant. The two populations never cross.
     */
    private function sharesBoundary(User $user, User $target): bool
    {
        if ($user->isPlatformUser()) {
            return $target->isPlatformUser() || $target->tenant_id !== null;
        }

        if ($target->isPlatformUser()) {
            return false;
        }

        return $user->tenant_id !== null && $user->tenant_id === $target->tenant_id;
    }
}
