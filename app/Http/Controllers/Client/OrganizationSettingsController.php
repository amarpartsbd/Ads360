<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organization profile settings (spec §14 Settings → Organization).
 *
 * Only presentational and contact details are editable here. The legal
 * identity that verification was granted against is not: changing it would
 * invalidate a decision compliance already made, so it goes through a fresh
 * verification submission instead.
 */
final class OrganizationSettingsController
{
    public function __construct(private readonly TenantContext $context) {}

    public function edit(): Response
    {
        $organization = $this->context->requireOrganization();

        Gate::authorize('view', $organization);

        return Inertia::render('Client/Settings/Organization', [
            'organization' => [
                'id' => $organization->public_id,
                'name' => $organization->name,
                'legalName' => $organization->legal_name,
                'businessType' => $organization->business_type,
                'website' => $organization->website,
                'contactEmail' => $organization->contact_email,
                'contactNumber' => $organization->contact_number,
                'country' => $organization->country,
                'timezone' => $organization->timezone,
                'currency' => $organization->default_currency,
                'status' => $organization->status->value,
                'statusLabel' => $organization->status->label(),
            ],
            'timezones' => \DateTimeZone::listIdentifiers(),
            'can' => ['update' => Gate::allows('update', $organization)],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = $this->context->requireOrganization();

        Gate::authorize('update', $organization);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'contact_email' => ['required', 'email:rfc,strict', 'max:255'],
            'contact_number' => ['required', 'string', 'max:32', 'regex:/^[0-9+\-\s()]+$/'],
            'timezone' => ['required', 'string', 'timezone'],
        ]);

        $before = AuditRecorder::snapshot($organization);

        $organization->fill($validated);
        $organization->save();

        if ($organization->wasChanged()) {
            app(AuditRecorder::class)->recordChange(
                action: AuditAction::OrganizationUpdated,
                resource: $organization,
                before: $before,
                organization: $organization,
            );
        }

        return back()->with('success', 'Organization settings saved.');
    }
}
