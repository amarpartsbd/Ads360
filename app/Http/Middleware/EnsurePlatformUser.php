<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the administration area (spec §92).
 *
 * Client and agency accounts are rejected here before any controller or policy
 * runs, so admin routes are not one forgotten policy away from being reachable.
 */
final class EnsurePlatformUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isPlatformUser()) {
            abort(403, 'This area is restricted to platform administrators.');
        }

        if ($user->status !== UserStatus::Active) {
            abort(403, 'This administrator account is not active.');
        }

        return $next($request);
    }
}
