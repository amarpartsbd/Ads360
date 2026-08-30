<?php

declare(strict_types=1);

namespace App\Domains\Billing\Policies;

use App\Domains\Billing\Models\PricingPlan;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;

/**
 * Authorization for pricing plans (spec §36).
 */
final class PricingPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::PricingView);
    }

    public function view(User $user, PricingPlan $plan): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::PricingView);
        }

        // A client may read the plan that prices them — the platform default,
        // their tenant's, or their own — and nobody else's.
        $appliesToThem = $plan->tenant_id === null
            || $plan->tenant_id === $user->tenant_id;

        return $appliesToThem && $user->hasPermissionTo(Permission::PricingView);
    }

    public function manage(User $user): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::PricingManage);
    }
}
