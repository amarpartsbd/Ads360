<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Policies;

use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;

/**
 * Authorization for the managed ad account inventory (spec §17, §68).
 *
 * Every method opens with the same test, and it is the important one: a client
 * user never reaches this table. The inventory is platform infrastructure —
 * which accounts exist, what they cost us, how close each is to a limit — and
 * exposing it would tell one client about accounts that also serve another.
 */
final class AdAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->platformUserWith($user, Permission::AdAccountsView);
    }

    public function view(User $user, AdAccount $account): bool
    {
        return $this->platformUserWith($user, Permission::AdAccountsView);
    }

    public function create(User $user): bool
    {
        return $this->platformUserWith($user, Permission::AdAccountsCreate);
    }

    public function update(User $user, AdAccount $account): bool
    {
        return $this->platformUserWith($user, Permission::AdAccountsUpdate);
    }

    /** Changing spend limits moves real money and is held separately. */
    public function manageLimits(User $user, AdAccount $account): bool
    {
        return $this->platformUserWith($user, Permission::AdAccountsManageHealth);
    }

    /** Recording a health or billing observation by hand. */
    public function manageHealth(User $user, AdAccount $account): bool
    {
        return $this->platformUserWith($user, Permission::AdAccountsManageHealth);
    }

    public function assign(User $user, AdAccount $account): bool
    {
        return $this->platformUserWith($user, Permission::AdAccountsAssign);
    }

    /**
     * Retiring an account. There is no delete: the account's spend history is
     * referenced by campaigns and reports, and spec §62 forbids removing
     * records that other records depend on.
     */
    public function retire(User $user, AdAccount $account): bool
    {
        return $this->platformUserWith($user, Permission::AdAccountsUpdate);
    }

    public function delete(User $user, AdAccount $account): bool
    {
        return false;
    }

    private function platformUserWith(User $user, Permission $permission): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo($permission);
    }
}
