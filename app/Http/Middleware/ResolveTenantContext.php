<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\MembershipStatus;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant and organization for the request (spec §5, STEP 13).
 *
 * Context comes from the authenticated user's own tenant and their active
 * memberships. The session may remember which organization was last selected,
 * but that value is re-verified against membership on every request — a
 * tampered session cookie buys nothing.
 *
 * Nothing in the request body, query string or headers is consulted.
 */
final class ResolveTenantContext
{
    public const SESSION_KEY = 'tenant.current_organization_id';

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        // Platform staff are intentionally unscoped: they act across tenants
        // through the admin surface, which has its own authorization.
        if ($user->isPlatformUser()) {
            return $next($request);
        }

        $tenant = $user->tenant;

        if ($tenant === null) {
            return $next($request);
        }

        $organization = $this->resolveOrganization($request, $user);

        $this->context->for($tenant, $organization);

        return $next($request);
    }

    private function resolveOrganization(Request $request, User $user): ?Organization
    {
        $remembered = $request->hasSession()
            ? $request->session()->get(self::SESSION_KEY)
            : null;

        if (is_int($remembered) || (is_string($remembered) && ctype_digit($remembered))) {
            $organization = $this->membershipOrganization($user, (int) $remembered);

            if ($organization !== null) {
                return $organization;
            }

            // The remembered organization is no longer accessible — drop it
            // rather than carrying a stale selection forward.
            $request->session()->forget(self::SESSION_KEY);
        }

        $organization = $user->activeOrganizations()
            ->orderByDesc('organization_user.is_primary')
            ->orderBy('organizations.id')
            ->first();

        if ($organization !== null && $request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $organization->getKey());
        }

        return $organization;
    }

    /** Re-verifies that the user still holds an access-granting membership. */
    private function membershipOrganization(User $user, int $organizationId): ?Organization
    {
        return $user->activeOrganizations()
            ->where('organizations.id', $organizationId)
            ->where('organizations.tenant_id', $user->tenant_id)
            ->wherePivot('status', MembershipStatus::Active->value)
            ->first();
    }
}
