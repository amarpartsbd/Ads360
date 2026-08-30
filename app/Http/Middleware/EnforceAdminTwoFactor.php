<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication is mandatory for administrators (spec §9).
 *
 * An admin without a confirmed authenticator is allowed only as far as the
 * pages needed to set one up, plus logout.
 */
final class EnforceAdminTwoFactor
{
    /**
     * Routes an administrator may reach while still enrolling.
     *
     * @var list<string>
     */
    private const EXEMPT_ROUTES = [
        'admin.security.two-factor.setup',
        'logout',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'user.confirm-password',
        'password.confirm',
        'password.confirmation',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('platform.security.admin_requires_two_factor')) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User || ! $user->isPlatformUser()) {
            return $next($request);
        }

        if ($user->hasTwoFactorEnabled() || $this->isExempt($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Two-factor authentication must be enabled on administrator accounts.');
        }

        return redirect()
            ->route('admin.security.two-factor.setup')
            ->with('warning', 'Enable two-factor authentication to continue.');
    }

    private function isExempt(Request $request): bool
    {
        $routeName = $request->route()?->getName();

        return $routeName !== null && in_array($routeName, self::EXEMPT_ROUTES, true);
    }
}
