<?php

declare(strict_types=1);

namespace App\Domains\Agency\Actions;

use App\Domains\Agency\Exceptions\AgencyException;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\OrganizationStatus;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An agency adds a client it manages (spec §42).
 *
 * The client is an organization inside the agency's own tenant. That is the
 * whole hierarchy — Platform → Agency → Agency Client — and it is why an agency
 * can never reach another agency's clients: they are in a different tenant, and
 * the global scope never crosses one.
 *
 * ## The client starts unverified, and the agency cannot change that
 *
 * A new client organization is PENDING. An agency vouching for its own client
 * would be the business being checked signing off on the check, so verification
 * stays a platform compliance decision (§11, OrganizationPolicy::verify). The
 * agency can build campaigns for the client immediately; what it cannot do is
 * make them spend before compliance has looked.
 */
final class CreateAgencyClient
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Tenant $agency, array $input, ?User $actor = null): Organization
    {
        $this->assertAgency($agency);

        return DB::transaction(function () use ($agency, $input, $actor): Organization {
            $name = trim((string) $input['name']);

            $organization = new Organization([
                'name' => $name,
                'slug' => $this->uniqueSlug($agency, $name),
                'legal_name' => $input['legal_name'] ?? null,
                'business_type' => $input['business_type'] ?? null,
                'country' => $input['country'] ?? $agency->country,
                'timezone' => $input['timezone'] ?? $agency->timezone,
                // The agency's currency by default: it is the agency that
                // funds the wallet and the agency that is invoiced.
                'default_currency' => $input['currency'] ?? $agency->default_currency,
                'website' => $input['website'] ?? null,
                'contact_email' => $input['contact_email'] ?? null,
                'contact_number' => $input['contact_number'] ?? null,
                'status' => OrganizationStatus::Pending,
            ]);

            // Set rather than filled: the tenant is context, never input.
            $organization->tenant_id = $agency->getKey();
            $organization->is_house_account = false;
            $organization->save();

            $this->audit->record(
                action: AuditAction::AgencyClientCreated,
                resource: $organization,
                after: ['name' => $organization->name, 'agency' => $agency->name],
                organization: $organization,
                actor: $actor,
            );

            // Refreshed so the caller sees the row as the database made it,
            // defaults included, rather than the half-built instance.
            return $organization->refresh();
        });
    }

    /**
     * @throws AgencyException
     */
    private function assertAgency(Tenant $agency): void
    {
        if (! config('platform.features.agency_module')) {
            throw AgencyException::moduleDisabled();
        }

        if (! $agency->type->managesClients()) {
            throw AgencyException::notAnAgency($agency);
        }
    }

    /**
     * Unique within the agency, not globally: two agencies may both have a
     * client called "Riverside Cafe", and neither should have to know that.
     */
    private function uniqueSlug(Tenant $agency, string $name): string
    {
        $base = Str::slug($name) ?: 'client';
        $slug = $base;
        $suffix = 1;

        while (
            Organization::query()
                ->withoutGlobalScopes()
                ->withTrashed()
                ->where('tenant_id', $agency->getKey())
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
