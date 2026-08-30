<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\OrganizationStatus;
use App\Domains\Tenant\Enums\TenantType;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * Client self-registration (spec §10).
 */
final class RegistrationTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    #[Test]
    public function registering_provisions_a_tenant_organization_membership_and_owner_role(): void
    {
        $this->post(route('register'), $this->validPayload())->assertRedirect();

        $user = User::query()->where('email', 'founder@newco.test')->firstOrFail();

        $tenant = Tenant::query()->where('name', 'New Co Limited')->firstOrFail();
        $this->assertSame(TenantType::DirectClient, $tenant->type);

        $organization = Organization::acrossTenants()->where('tenant_id', $tenant->getKey())->firstOrFail();

        // Not usable until the business is verified (spec §11).
        $this->assertSame(OrganizationStatus::Pending, $organization->status);
        $this->assertSame(UserStatus::PendingVerification, $user->status);
        $this->assertNull($user->email_verified_at);

        $this->assertSame($tenant->getKey(), $user->tenant_id);
        $this->assertFalse($user->isPlatformUser());
        $this->assertTrue($user->belongsToOrganization($organization));
        $this->assertTrue($user->hasPermissionTo('campaigns.create', $organization));
    }

    #[Test]
    public function a_registration_never_creates_a_platform_account(): void
    {
        $this->post(route('register'), $this->validPayload());

        $user = User::query()->where('email', 'founder@newco.test')->firstOrFail();

        $this->assertFalse($user->is_platform_user);
        $this->assertFalse($user->hasPermissionTo('system.manage'));
        $this->assertFalse($user->hasPermissionTo('clients.verify'));
    }

    #[Test]
    public function registration_rejects_a_weak_password(): void
    {
        $this->post(route('register'), [...$this->validPayload(), 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function registration_requires_accepting_the_terms(): void
    {
        $payload = $this->validPayload();
        unset($payload['terms']);

        $this->post(route('register'), $payload)->assertSessionHasErrors('terms');

        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function registration_requires_the_business_fields(): void
    {
        $this->post(route('register'), [])
            ->assertSessionHasErrors(['name', 'email', 'mobile_number', 'company_name', 'business_type', 'country']);
    }

    #[Test]
    public function a_duplicate_email_is_rejected_and_leaves_no_partial_workspace(): void
    {
        $this->post(route('register'), $this->validPayload())->assertSessionHasNoErrors();

        $tenantsAfterFirst = Tenant::query()->count();
        $this->assertSame(1, $tenantsAfterFirst, 'The first registration should have provisioned a tenant.');

        // Registration signs the new owner in, so the second attempt is made as
        // a fresh visitor — which is what a duplicate signup actually looks like.
        auth()->logout();

        $this->post(route('register'), $this->validPayload())->assertSessionHasErrors('email');

        // The whole provisioning runs in one transaction, so a rejected second
        // attempt leaves nothing behind.
        $this->assertSame($tenantsAfterFirst, Tenant::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'name' => 'Ada Founder',
            'email' => 'founder@newco.test',
            'mobile_number' => '+8801711111111',
            'company_name' => 'New Co Limited',
            'business_type' => 'Retail',
            'country' => 'BD',
            'password' => 'Correct-Horse-Battery-9!',
            'password_confirmation' => 'Correct-Horse-Battery-9!',
            'terms' => true,
        ];
    }
}
