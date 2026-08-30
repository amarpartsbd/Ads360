<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Policies;

use App\Domains\Advertising\Models\AdAccountPool;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;

/**
 * Authorization for ad account pools (spec §18, §68).
 *
 * Reading a pool needs only the inventory permission, because a pool is a view
 * over accounts the holder can already see. Changing one — its rules, its
 * membership — is a sensitive action: allocation rules decide which client's
 * money runs through which account, so the permission behind them is held
 * separately and marked sensitive in the registry.
 */
final class AdAccountPoolPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->platformUserWith($user, Permission::AdAccountsView);
    }

    public function view(User $user, AdAccountPool $pool): bool
    {
        return $this->platformUserWith($user, Permission::AdAccountsView);
    }

    public function create(User $user): bool
    {
        return $this->platformUserWith($user, Permission::AdAccountsManagePools);
    }

    public function update(User $user, AdAccountPool $pool): bool
    {
        return $this->platformUserWith($user, Permission::AdAccountsManagePools);
    }

    /** Adding or removing accounts, and changing their weights. */
    public function manageMembers(User $user, AdAccountPool $pool): bool
    {
        return $this->platformUserWith($user, Permission::AdAccountsManagePools);
    }

    public function archive(User $user, AdAccountPool $pool): bool
    {
        return $this->platformUserWith($user, Permission::AdAccountsManagePools);
    }

    public function delete(User $user, AdAccountPool $pool): bool
    {
        return false;
    }

    private function platformUserWith(User $user, Permission $permission): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo($permission);
    }
}
