<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\MembershipStatus;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use RuntimeException;

/**
 * Fixtures for tenancy tests.
 *
 * Builds complete, realistic workspaces — tenant, organization, member and role
 * grant — because a test that skips the membership row would not be testing the
 * same path a real request takes.
 */
trait CreatesTenantWorkspaces
{
    protected function seedAccessControl(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    /**
     * @return array{tenant: Tenant, organization: Organization, user: User}
     */
    protected function createWorkspace(string $roleSlug = 'client-owner', ?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::factory()->create();
        $organization = Organization::factory()->forTenant($tenant)->create();
        $user = User::factory()->memberOf($organization)->create();

        $this->grantRole($user, $roleSlug, $organization);

        return ['tenant' => $tenant, 'organization' => $organization, 'user' => $user];
    }

    protected function grantRole(User $user, string $roleSlug, ?Organization $organization = null): void
    {
        /** @var Role|null $role */
        $role = Role::query()->whereNull('tenant_id')->where('slug', $roleSlug)->first();

        if ($role === null) {
            throw new RuntimeException(
                "System role [{$roleSlug}] not found. Call seedAccessControl() first."
            );
        }

        $user->roles()->attach($role->getKey(), [
            'organization_id' => $organization?->getKey(),
            'tenant_id' => $user->tenant_id,
        ]);

        $user->forgetCachedPermissions();
    }

    protected function createPlatformUser(string $roleSlug = 'super-admin'): User
    {
        $user = User::factory()->platform()->create();

        $this->grantRole($user, $roleSlug);

        return $user;
    }

    /**
     * A verification profile for an organization, in whatever state the test
     * needs. Created through the factory so the shape stays in one place.
     */
    protected function createVerificationProfile(
        Organization $organization,
        VerificationStatus $status = VerificationStatus::NotSubmitted,
    ): VerificationProfile {
        $state = match ($status) {
            VerificationStatus::Pending => 'submitted',
            VerificationStatus::UnderReview => 'underReview',
            VerificationStatus::Verified => 'verified',
            VerificationStatus::RequiresInformation => 'requiresInformation',
            default => null,
        };

        $factory = VerificationProfile::factory()->forOrganization($organization);

        if ($state !== null) {
            $factory = $factory->{$state}();
        } else {
            $factory = $factory->state(['status' => $status]);
        }

        return $factory->create();
    }

    /** Adds an existing user to another organization within their own tenant. */
    protected function addMembership(
        User $user,
        Organization $organization,
        MembershipStatus $status = MembershipStatus::Active,
    ): void {
        $organization->members()->attach($user->getKey(), [
            'tenant_id' => $organization->tenant_id,
            'status' => $status->value,
            'is_primary' => false,
            'joined_at' => now(),
        ]);
    }
}
