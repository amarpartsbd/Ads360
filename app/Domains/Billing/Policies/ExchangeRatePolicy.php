<?php

declare(strict_types=1);

namespace App\Domains\Billing\Policies;

use App\Domains\Billing\Models\ExchangeRate;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;

/**
 * Authorization for exchange rates (spec §35).
 *
 * Clients may see the rate they transact at; only platform staff may change it,
 * and a rate is never edited — publishing a new one closes the old.
 */
final class ExchangeRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::ExchangeRatesView);
    }

    public function view(User $user, ExchangeRate $rate): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::ExchangeRatesView);
        }

        // A tenant sees the platform card and its own, never another tenant's.
        return ($rate->tenant_id === null || $rate->tenant_id === $user->tenant_id)
            && $user->hasPermissionTo(Permission::ExchangeRatesView);
    }

    public function manage(User $user): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::ExchangeRatesManage);
    }

    public function update(User $user, ExchangeRate $rate): bool
    {
        return false;
    }

    public function delete(User $user, ExchangeRate $rate): bool
    {
        return false;
    }
}
