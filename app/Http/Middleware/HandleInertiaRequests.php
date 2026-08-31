<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

/**
 * Shared Inertia props.
 *
 * Only what the interface needs is shared. Provider tokens, internal risk
 * notes, other tenants' identifiers and admin-only metadata never appear here
 * (spec §54).
 */
final class HandleInertiaRequests extends Middleware
{
    /** How many workspaces the switcher carries before it is a list to browse. */
    private const SWITCHER_LIMIT = 50;

    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $context = app(TenantContext::class);

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $user instanceof User ? $this->serialiseUser($user, $context) : null,
            ],

            'tenant' => $context->hasTenant()
                ? [
                    'id' => $context->requireTenant()->public_id,
                    'name' => $context->requireTenant()->name,
                    'type' => $context->requireTenant()->type->value,
                    /*
                     * Whether this tenant is an agency or reseller (spec §42).
                     * The interface uses it to decide whether a clients
                     * section exists at all; the server still authorizes every
                     * request on its own (Rule 9).
                     */
                    'managesClients' => $context->requireTenant()->type->managesClients()
                        && (bool) config('platform.features.agency_module'),
                    'branding' => $context->requireTenant()->brandingWithDefaults(),
                ]
                : null,

            // Named distinctly from any page's own `organization` prop: a page
            // prop of the same name would silently shadow this one and break
            // the workspace switcher.
            'currentOrganization' => $context->hasOrganization()
                ? $this->serialiseOrganization($context->requireOrganization())
                : null,

            /*
             * The workspace switcher. Capped, because an agency owner reaches
             * every client of their agency (spec §42) and a large one would
             * otherwise ship hundreds of rows on every response. The clients
             * screen is where a long list is browsed and searched; this is for
             * hopping between the few that are open.
             */
            'organizations' => fn (): array => $user instanceof User && ! $user->isPlatformUser()
                ? $user->reachableOrganizations()
                    ->orderBy('organizations.name')
                    ->limit(self::SWITCHER_LIMIT)
                    ->get()
                    ->map(fn (Organization $organization): array => $this->serialiseOrganization($organization))
                    ->all()
                : [],

            'platform' => [
                'name' => config('platform.name'),
                'support_email' => config('platform.support_email'),
            ],

            'features' => config('platform.features'),

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
            ],

            'ziggy' => fn (): array => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialiseUser(User $user, TenantContext $context): array
    {
        return [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'is_platform_user' => $user->isPlatformUser(),
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'email_verified' => $user->hasVerifiedEmail(),
            'timezone' => $user->timezone,
            // The interface hides controls the user cannot use; the server
            // still authorizes every action independently.
            'permissions' => $user->permissionsFor($context->organization())->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialiseOrganization(Organization $organization): array
    {
        return [
            'id' => $organization->public_id,
            'name' => $organization->name,
            'status' => $organization->status->value,
            'currency' => $organization->default_currency,
            'timezone' => $organization->timezone,
        ];
    }
}
