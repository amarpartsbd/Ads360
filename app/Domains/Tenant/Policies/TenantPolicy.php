<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Policies;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Tenant;

/**
 * Tenant records are platform-administration objects.
 *
 * A tenant user may read their own tenant — that is how branding and workspace
 * details reach the interface — but may never read another one, and may never
 * change tenant state such as suspension.
 */
final class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::ClientsView);
    }

    public function view(User $user, Tenant $tenant): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::ClientsView);
        }

        return $user->tenant_id === $tenant->getKey();
    }

    public function create(User $user): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::ClientsCreate);
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::ClientsUpdate);
    }

    public function suspend(User $user, Tenant $tenant): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::ClientsSuspend);
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return false;
    }
}
