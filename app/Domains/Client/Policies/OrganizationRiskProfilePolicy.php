<?php

declare(strict_types=1);

namespace App\Domains\Client\Policies;

use App\Domains\Client\Models\OrganizationRiskProfile;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;

/**
 * Authorization for risk profiles (spec §12, §54).
 *
 * Platform staff only, in both directions. A risk score is an internal
 * judgement about a client's business built partly from things they cannot see
 * — a compliance officer's note, a pattern across their payments — and §54
 * names internal risk notes among the things never exposed outside the
 * platform. A client is told what to *do* (complete verification, fix a payment
 * method) through their own screens, never handed the score.
 */
final class OrganizationRiskProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::RiskView);
    }

    public function view(User $user, OrganizationRiskProfile $profile): bool
    {
        return $this->viewAny($user);
    }

    /** Flagging, clearing and reviewing are all the same authority. */
    public function manage(User $user): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::RiskManage);
    }

    /**
     * A risk profile is never deleted. It is the record of what the platform
     * believed about a client and when, which is exactly what an audit of a
     * suspension or a refused payment would ask for (spec §62).
     */
    public function delete(User $user, OrganizationRiskProfile $profile): bool
    {
        return false;
    }
}
