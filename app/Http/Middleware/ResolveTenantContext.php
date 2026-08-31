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
 * Context comes from the authenticated user's own tenant and the organizations
 * they can reach — their active memberships, or, for an agency owner, every
 * client of their own agency (spec §42). The session may remember which
 * organization was last selected, but that value is re-verified on every
 * request — a tampered session cookie buys nothing.
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

        $organization = $this->defaultOrganization($user);

        if ($organization !== null && $request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $organization->getKey());
        }

        return $organization;
    }

    /**
     * Which organization to land in when nothing has been chosen.
     *
     * A member's primary organization comes first, which is what a client user
     * expects. An agency owner has no memberships to be primary in, so they
     * land on the tenant's first organization — their own house account, which
     * is created before any client.
     */
    private function defaultOrganization(User $user): ?Organization
    {
        $membership = $user->activeOrganizations()
            ->orderByDesc('organization_user.is_primary')
            ->orderBy('organizations.id')
            ->first();

        if ($membership !== null || ! $user->actsAcrossTenant()) {
            return $membership;
        }

        return $user->reachableOrganizations()->orderBy('organizations.id')->first();
    }

    /**
     * Re-verifies that the user can still reach the remembered organization.
     *
     * A membership is one way in; an agency-wide grant is the other. Either
     * way this is re-read from the database on every request, so a session
     * carrying an organization the user has since lost access to buys nothing
     * (spec §5).
     */
    private function membershipOrganization(User $user, int $organizationId): ?Organization
    {
        if ($user->actsAcrossTenant()) {
            return $user->reachableOrganizations()
                ->where('organizations.id', $organizationId)
                ->first();
        }

        return $user->activeOrganizations()
            ->where('organizations.id', $organizationId)
            ->where('organizations.tenant_id', $user->tenant_id)
            ->wherePivot('status', MembershipStatus::Active->value)
            ->first();
    }
}
