<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

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
use RuntimeException;

/**
 * Client self-registration (spec §10).
 *
 * A registration creates the whole hierarchy at once — tenant, organization,
 * the owner's membership and their role grant — inside a single transaction, so
 * a half-built workspace can never exist.
 *
 * The account is deliberately not usable yet: the user must verify their email,
 * and the organization stays PENDING until business verification is reviewed.
 */
final class RegisterClient
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(array $input): User
    {
        return DB::transaction(function () use ($input): User {
            $companyName = (string) $input['company_name'];

            $tenant = Tenant::create([
                'name' => $companyName,
                'slug' => $this->uniqueTenantSlug($companyName),
                // Self-registration always creates a direct client. Agencies and
                // resellers are provisioned by platform staff.
                'type' => TenantType::DirectClient,
                'status' => TenantStatus::Active,
                'billing_email' => (string) $input['email'],
                'country' => $input['country'] ?? null,
                'timezone' => config('platform.default_timezone'),
                'default_currency' => config('platform.default_currency'),
            ]);

            $organization = new Organization([
                'name' => $companyName,
                'slug' => Str::slug($companyName) ?: 'workspace',
                'business_type' => $input['business_type'] ?? null,
                'country' => $input['country'] ?? null,
                'timezone' => $tenant->timezone,
                'default_currency' => $tenant->default_currency,
                'contact_email' => (string) $input['email'],
                'contact_number' => $input['mobile_number'] ?? null,
                // Business verification has not happened yet (spec §11).
                'status' => OrganizationStatus::Pending,
            ]);
            $organization->tenant_id = $tenant->getKey();
            $organization->save();

            $user = new User([
                'name' => (string) $input['name'],
                'email' => (string) $input['email'],
                'mobile_number' => $input['mobile_number'] ?? null,
                'password' => (string) $input['password'],
                'status' => UserStatus::PendingVerification,
                'timezone' => $tenant->timezone,
                'terms_accepted_at' => Carbon::now(),
            ]);
            $user->tenant_id = $tenant->getKey();
            $user->is_platform_user = false;
            $user->save();

            $organization->members()->attach($user->getKey(), [
                'tenant_id' => $tenant->getKey(),
                'status' => MembershipStatus::Active->value,
                'is_primary' => true,
                'joined_at' => Carbon::now(),
            ]);

            $this->grantOwnerRole($user, $organization, $tenant);

            $this->audit->record(
                action: AuditAction::UserRegistered,
                resource: $user,
                after: ['email' => $user->email, 'organization' => $organization->name],
                organization: $organization,
                actor: $user,
            );

            return $user;
        });
    }

    private function grantOwnerRole(User $user, Organization $organization, Tenant $tenant): void
    {
        $role = Role::query()
            ->whereNull('tenant_id')
            ->where('slug', 'client-owner')
            ->where('scope', RoleScope::Organization->value)
            ->first();

        if ($role === null) {
            // The system roles are seeded as part of deployment. Registering
            // without them would create an owner who cannot do anything, so
            // fail rather than produce a broken workspace.
            throw new RuntimeException('The client-owner system role is missing. Run the role seeder.');
        }

        $user->roles()->attach($role->getKey(), [
            'organization_id' => $organization->getKey(),
            'tenant_id' => $tenant->getKey(),
        ]);
    }

    private function uniqueTenantSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';
        $slug = $base;
        $suffix = 1;

        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
