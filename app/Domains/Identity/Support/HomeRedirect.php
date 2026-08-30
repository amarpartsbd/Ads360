<?php

declare(strict_types=1);

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Models\User;

/**
 * Where a user lands after authenticating.
 *
 * Platform staff go to the administration area, everyone else to the client
 * application (spec §92).
 */
final class HomeRedirect
{
    public static function for(?User $user): string
    {
        return $user?->isPlatformUser() === true
            ? route('admin.dashboard')
            : route('client.dashboard');
    }
}
