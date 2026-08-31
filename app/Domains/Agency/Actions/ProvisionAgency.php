<?php

declare(strict_types=1);

namespace App\Domains\Agency\Actions;

use App\Domains\Agency\Exceptions\AgencyException;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Enums\RoleScope;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\MembershipStatus;
use App\Domains\Tenant\Enums\OrganizationStatus;
use App\Domains\Tenant\Enums\TenantStatus;
use App\Domains\Tenant\Enums\TenantType;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Platform staff create an agency or reseller (spec §42).
 *
 * Deliberately not self-service. Registration always produces a direct client;
 * an agency is a commercial relationship — its own pricing, its own clients,
 * staff who can see other businesses' spend — and it is the platform that
 * decides a business is one.
 *
 * The whole hierarchy is built in one transaction: the tenant, the agency's own
 * house organization, the owner and their grant. A half-provisioned agency —
 * a tenant with no organization, or an owner with no role — would be an account
 * that authenticates and can do nothing.
 *
 * ## Why the owner's grant carries no organization
 *
 * The agency-owner role is declared at TENANT scope, and it is attached here
 * with a null organization. That is what makes it mean "this agency" rather
 * than "this one workspace": the owner reaches every client the agency manages,
 * including clients created years later, and revoking them is one row rather
 * than one per client (see User::actsAcrossTenant).
 */
final class ProvisionAgency
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{tenant: Tenant, organization: Organization, owner: User}
     */
    public function handle(array $input, ?User $actor = null): array
    {
        if (! config('platform.features.agency_module')) {
            throw AgencyException::moduleDisabled();
        }

        $type = $input['type'] instanceof TenantType
            ? $input['type']
            : TenantType::from((string) $input['type']);

        if (! $type->managesClients()) {
            throw new AgencyException(
                "A {$type->label()} tenant does not manage clients and cannot be provisioned as an agency."
            );
        }

        return DB::transaction(function () use ($input, $type, $actor): array {
            $name = trim((string) $input['name']);

            $tenant = Tenant::create([
                'name' => $name,
                'slug' => $this->uniqueTenantSlug($name),
                'type' => $type,
                'status' => TenantStatus::Active,
                'billing_email' => (string) $input['billing_email'],
                'country' => $input['country'] ?? config('platform.default_country'),
                'timezone' => $input['timezone'] ?? config('platform.default_timezone'),
                'default_currency' => $input['currency'] ?? config('platform.default_currency'),
            ]);

            $organization = new Organization([
                'name' => $name,
                'slug' => Str::slug($name) ?: 'agency',
                'country' => $tenant->country,
                'timezone' => $tenant->timezone,
                'default_currency' => $tenant->default_currency,
                'contact_email' => (string) $input['billing_email'],
                /*
                 * Pending, like any other business. An agency does not get to
                 * skip verification because it is an agency — its own account
                 * can run ads, and §11 applies to it too.
                 */
                'status' => OrganizationStatus::Pending,
            ]);
            $organization->tenant_id = $tenant->getKey();
            $organization->is_house_account = true;
            $organization->save();

            $owner = $this->createOwner($input, $tenant, $organization);

            $this->audit->record(
                action: AuditAction::AgencyProvisioned,
                resource: $tenant,
                after: [
                    'name' => $tenant->name,
                    'type' => $type->value,
                    'owner_email' => $owner->email,
                ],
                organization: $organization,
                actor: $actor,
            );

            /*
             * Refreshed before returning: each of these was built in memory
             * and saved, so column defaults the database filled in are not on
             * the instance yet. A caller that went straight on to read one
             * would find an attribute missing rather than its default.
             */
            return [
                'tenant' => $tenant->refresh(),
                'organization' => $organization->refresh(),
                'owner' => $owner->refresh(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function createOwner(array $input, Tenant $tenant, Organization $organization): User
    {
        $owner = new User([
            'name' => (string) $input['owner_name'],
            'email' => (string) $input['owner_email'],
            'password' => (string) $input['owner_password'],
            // Verifies their own email like anyone else; provisioning does not
            // confirm that whoever typed the address controls it.
            'status' => UserStatus::PendingVerification,
            'timezone' => $tenant->timezone,
            'terms_accepted_at' => Carbon::now(),
        ]);
        $owner->tenant_id = $tenant->getKey();
        $owner->is_platform_user = false;
        $owner->save();

        /*
         * The owner is a member of the house account as well as holding the
         * tenant-wide grant. The grant is what reaches the clients; the
         * membership is what makes the agency's own workspace the one they
         * land in.
         */
        $organization->members()->attach($owner->getKey(), [
            'tenant_id' => $tenant->getKey(),
            'status' => MembershipStatus::Active->value,
            'is_primary' => true,
            'joined_at' => Carbon::now(),
        ]);

        $role = Role::query()
            ->whereNull('tenant_id')
            ->where('slug', 'agency-owner')
            ->where('scope', RoleScope::Tenant->value)
            ->first();

        if ($role === null) {
            throw AgencyException::staffRoleUnavailable('agency-owner');
        }

        $owner->roles()->attach($role->getKey(), [
            // Null on purpose: this is the grant that spans the agency.
            'organization_id' => null,
            'tenant_id' => $tenant->getKey(),
        ]);

        return $owner;
    }

    private function uniqueTenantSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'agency';
        $slug = $base;
        $suffix = 1;

        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
