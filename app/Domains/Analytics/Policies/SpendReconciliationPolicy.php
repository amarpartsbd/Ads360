<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Policies;

use App\Domains\Analytics\Models\SpendReconciliation;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;

/**
 * Authorization for spend reconciliation (spec §78, §68).
 *
 * Platform-only throughout. A discrepancy between what a provider says and
 * what the platform charged is an internal finance matter; showing it to a
 * client would raise a question about their bill that nobody has answered yet.
 */
final class SpendReconciliationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::ReportsView);
    }

    public function view(User $user, SpendReconciliation $reconciliation): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Settling a discrepancy is a finance decision, so it needs a finance
     * permission — not merely the ability to read reports.
     */
    public function resolve(User $user, SpendReconciliation $reconciliation): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::WalletAdjust);
    }

    public function delete(User $user, SpendReconciliation $reconciliation): bool
    {
        return false;
    }
}
