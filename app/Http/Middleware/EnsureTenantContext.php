<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses to serve a client-application request that has no tenant context.
 *
 * Without this, a resolution bug would silently produce unscoped queries: the
 * global scope only filters when a tenant is bound. Failing closed here means a
 * missing context is an error, never a data leak.
 */
final class EnsureTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        // Platform staff have no tenant of their own and never reach the client
        // application; the admin surface is guarded separately.
        if ($user->isPlatformUser()) {
            abort(403, 'Platform accounts use the administration area.');
        }

        if (! $this->context->hasTenant()) {
            abort(403, 'Your account is not associated with an active workspace.');
        }

        if (! $this->context->requireTenant()->isOperational()) {
            abort(403, 'This workspace is not active. Please contact support.');
        }

        if (! $this->context->hasOrganization()) {
            // The user is in a tenant but has no organization they may enter,
            // which is a legitimate state during onboarding.
            abort(403, 'You do not have access to an organization yet.');
        }

        return $next($request);
    }
}
