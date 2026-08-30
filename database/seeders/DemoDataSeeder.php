<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\MembershipStatus;
use App\Domains\Tenant\Enums\OrganizationStatus;
use App\Domains\Tenant\Enums\TenantStatus;
use App\Domains\Tenant\Enums\TenantType;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Development fixtures (spec §94).
 *
 * Refuses to run in production, and every credential here is a throwaway
 * placeholder — no real address, secret or client data belongs in a seeder.
 */
class DemoDataSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'Ads360-Demo-Password!1';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data must never be seeded in production.');
        }

        $this->seedPlatformStaff();
        $this->seedDirectClient();
        $this->seedAgency();
        $this->seedPendingVerification();
    }

    private function seedPlatformStaff(): void
    {
        $accounts = [
            ['super-admin', 'Platform Owner', 'owner@ads360.test'],
            ['operations-admin', 'Operations Admin', 'ops@ads360.test'],
            ['finance-admin', 'Finance Admin', 'finance@ads360.test'],
            ['compliance-admin', 'Compliance Admin', 'compliance@ads360.test'],
            ['support-agent', 'Support Agent', 'support@ads360.test'],
        ];

        foreach ($accounts as [$roleSlug, $name, $email]) {
            $user = $this->makeUser($name, $email, tenant: null, platform: true);
            $this->grant($user, $roleSlug);
        }
    }

    private function seedDirectClient(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'demo-retail'],
            [
                'name' => 'Demo Retail Ltd',
                'type' => TenantType::DirectClient,
                'status' => TenantStatus::Active,
                'billing_email' => 'billing@demo-retail.test',
                'country' => 'BD',
                'timezone' => 'Asia/Dhaka',
                'default_currency' => 'BDT',
            ],
        );

        $organization = $this->makeOrganization($tenant, 'Demo Retail Ltd', 'demo-retail');

        $members = [
            ['client-owner', 'Demo Client Owner', 'client.owner@demo-retail.test'],
            ['client-marketer', 'Demo Marketer', 'marketer@demo-retail.test'],
            ['client-accountant', 'Demo Accountant', 'accountant@demo-retail.test'],
            ['client-viewer', 'Demo Viewer', 'viewer@demo-retail.test'],
        ];

        foreach ($members as [$roleSlug, $name, $email]) {
            $user = $this->makeUser($name, $email, $tenant);
            $this->addMember($organization, $user);
            $this->grant($user, $roleSlug, $organization);
        }
    }

    /**
     * A second client sitting in the compliance queue, so the review screens
     * have something realistic to show in development.
     */
    private function seedPendingVerification(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'demo-pending'],
            [
                'name' => 'Riverside Foods',
                'type' => TenantType::DirectClient,
                'status' => TenantStatus::Active,
                'billing_email' => 'billing@riverside-foods.test',
                'country' => 'BD',
                'timezone' => 'Asia/Dhaka',
                'default_currency' => 'BDT',
            ],
        );

        $organization = $this->makeOrganization($tenant, 'Riverside Foods', 'riverside-foods');
        $organization->forceFill(['status' => OrganizationStatus::UnderReview])->save();

        $owner = $this->makeUser('Riverside Owner', 'owner@riverside-foods.test', $tenant);
        $this->addMember($organization, $owner);
        $this->grant($owner, 'client-owner', $organization);

        $exists = VerificationProfile::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->exists();

        if ($exists) {
            return;
        }

        $profile = new VerificationProfile([
            'legal_business_name' => 'Riverside Foods Limited',
            'trading_name' => 'Riverside Foods',
            'business_type' => 'Food and beverage',
            'website' => 'https://riverside-foods.test',
            'contact_number' => '+8801700000010',
            'business_email' => 'hello@riverside-foods.test',
            'address_line_1' => '42 Riverside Road',
            'city' => 'Dhaka',
            'state' => 'Dhaka',
            'postal_code' => '1207',
            'country' => 'BD',
            'authorized_person_name' => 'Riverside Owner',
            'authorized_person_designation' => 'Managing Director',
            'authorized_person_email' => 'owner@riverside-foods.test',
            'authorized_person_phone' => '+8801700000011',
            'trade_license_number' => 'TRAD-000000',
            'tin' => '100000000',
            'expected_monthly_spend_minor' => 75_000_00,
            'expected_monthly_spend_currency' => 'BDT',
            'advertising_category' => 'Restaurants',
        ]);

        $profile->organization_id = $organization->getKey();
        $profile->tenant_id = $tenant->getKey();
        $profile->status = VerificationStatus::Pending;
        $profile->submitted_at = now()->subDays(2);
        $profile->save();
    }

    private function seedAgency(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'demo-agency'],
            [
                'name' => 'Demo Media Agency',
                'type' => TenantType::Agency,
                'status' => TenantStatus::Active,
                'billing_email' => 'billing@demo-agency.test',
                'country' => 'BD',
                'timezone' => 'Asia/Dhaka',
                'default_currency' => 'BDT',
            ],
        );

        // An agency holds one organization per client it manages.
        $house = $this->makeOrganization($tenant, 'Demo Media Agency', 'demo-agency');
        $client = $this->makeOrganization($tenant, 'Agency Client — Riverside Cafe', 'riverside-cafe');

        $owner = $this->makeUser('Demo Agency Owner', 'agency.owner@demo-agency.test', $tenant);
        $this->addMember($house, $owner);
        $this->addMember($client, $owner);
        $this->grant($owner, 'agency-owner');

        $manager = $this->makeUser('Demo Agency Manager', 'agency.manager@demo-agency.test', $tenant);
        $this->addMember($client, $manager);
        $this->grant($manager, 'agency-manager', $client);
    }

    private function makeUser(string $name, string $email, ?Tenant $tenant, bool $platform = false): User
    {
        /** @var User $user */
        $user = User::query()->firstOrNew(['email' => $email]);

        $user->fill([
            'name' => $name,
            'mobile_number' => '+8801700000000',
            'status' => UserStatus::Active,
            'timezone' => 'Asia/Dhaka',
            'terms_accepted_at' => now(),
        ]);

        $user->tenant_id = $tenant?->getKey();
        $user->is_platform_user = $platform;
        $user->email_verified_at ??= now();

        if (! $user->exists) {
            $user->password = self::DEMO_PASSWORD;
        }

        $user->save();

        return $user;
    }

    private function makeOrganization(Tenant $tenant, string $name, string $slug): Organization
    {
        /** @var Organization|null $existing */
        $existing = Organization::acrossTenants()
            ->where('tenant_id', $tenant->getKey())
            ->where('slug', $slug)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $organization = new Organization([
            'name' => $name,
            'slug' => $slug,
            'business_type' => 'Retail',
            'country' => 'BD',
            'timezone' => $tenant->timezone,
            'default_currency' => $tenant->default_currency,
            'contact_email' => "hello@{$slug}.test",
            'contact_number' => '+8801700000000',
            'status' => OrganizationStatus::Active,
            'activated_at' => now(),
        ]);

        $organization->tenant_id = $tenant->getKey();
        $organization->save();

        return $organization;
    }

    private function addMember(Organization $organization, User $user): void
    {
        $organization->members()->syncWithoutDetaching([
            $user->getKey() => [
                'tenant_id' => $organization->tenant_id,
                'status' => MembershipStatus::Active->value,
                'is_primary' => ! $organization->members()->where('users.id', '!=', $user->getKey())->exists(),
                'joined_at' => now(),
            ],
        ]);
    }

    private function grant(User $user, string $roleSlug, ?Organization $organization = null): void
    {
        /** @var Role|null $role */
        $role = Role::query()->whereNull('tenant_id')->where('slug', $roleSlug)->first();

        if ($role === null) {
            throw new RuntimeException("System role [{$roleSlug}] is missing. Run RoleSeeder first.");
        }

        $exists = $user->roles()
            ->wherePivot('role_id', $role->getKey())
            ->wherePivot('organization_id', $organization?->getKey())
            ->exists();

        if ($exists) {
            return;
        }

        $user->roles()->attach($role->getKey(), [
            'organization_id' => $organization?->getKey(),
            'tenant_id' => $user->tenant_id,
        ]);
    }
}
