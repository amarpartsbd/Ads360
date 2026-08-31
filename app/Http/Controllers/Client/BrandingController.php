<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Actions\UpdateBranding;
use App\Domains\Tenant\Services\TenantContext;
use App\Domains\Tenant\Values\Branding;
use App\Http\Requests\Client\UpdateBrandingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * White-label branding for the current workspace (spec §43).
 *
 * Tenant-level rather than organization-level: branding is what a tenant's
 * whole copy of the platform looks like, and an agency whose clients each saw a
 * different logo would have no white label at all.
 */
final class BrandingController
{
    public function __construct(private readonly TenantContext $context) {}

    public function edit(Request $request): Response
    {
        $tenant = $this->tenant();
        $branding = $tenant->brandingValue();

        return Inertia::render('Client/Settings/Branding', [
            'branding' => [
                'name' => $branding->name,
                'logo_url' => $branding->logoUrl,
                'primary_color' => $branding->primaryColor,
                'support_email' => $branding->supportEmail,
                'custom_domain' => $tenant->custom_domain,
            ],
            'defaults' => [
                'name' => config('platform.name'),
                'support_email' => config('platform.support_email'),
            ],
            'contrast' => [
                'minimum' => Branding::minimumContrast(),
            ],
            'can' => ['update' => $this->actor($request)->can('branding.manage')],
        ]);
    }

    public function update(UpdateBrandingRequest $request, UpdateBranding $update): RedirectResponse
    {
        $tenant = $this->tenant();

        $validated = $request->validated();

        $update->handle(
            tenant: $tenant,
            branding: [
                'name' => $validated['name'] ?? null,
                'logo_url' => $validated['logo_url'] ?? null,
                'primary_color' => $validated['primary_color'] ?? null,
                'support_email' => $validated['support_email'] ?? null,
            ],
            customDomain: $validated['custom_domain'] ?? null,
            actor: $this->actor($request),
        );

        return back()->with('success', 'Branding saved.');
    }

    private function tenant(): \App\Domains\Tenant\Models\Tenant
    {
        $tenant = $this->context->requireTenant();

        if (! $tenant->canWhiteLabel()) {
            // Not a 403: with the feature off, this screen describes nothing
            // that exists.
            throw new NotFoundHttpException;
        }

        return $tenant;
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
