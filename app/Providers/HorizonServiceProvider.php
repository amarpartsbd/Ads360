<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Horizon::routeSmsNotificationsTo(null);
    }

    /**
     * The Horizon dashboard exposes queue payloads, so it is restricted to
     * platform staff holding `system.manage` (spec §79).
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user): bool {
            return $user !== null
                && $user->isPlatformUser()
                && $user->hasPermissionTo(Permission::SystemManage);
        });
    }
}
