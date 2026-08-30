<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Audit\Enums\ActorType;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * The mandatory tenant isolation suite (spec §68).
 *
 * These tests are the gate on Phase 0: no later module ships until they pass.
 * They exercise each of the three independent defences separately — the query
 * scope, the authorization policies, and the ownership checks in the HTTP
 * layer — so a regression in any one of them is caught on its own rather than
 * being masked by the other two.
 */
final class TenantIsolationTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    // ------------------------------------------------------------------
    // Query scoping
    // ------------------------------------------------------------------

    #[Test]
    public function the_global_scope_hides_organizations_belonging_to_another_tenant(): void
    {
        $alpha = $this->createWorkspace();
        $beta = $this->createWorkspace();

        app(TenantContext::class)->for($alpha['tenant']);

        $visible = Organization::query()->pluck('id');

        $this->assertTrue($visible->contains($alpha['organization']->getKey()));
        $this->assertFalse(
            $visible->contains($beta['organization']->getKey()),
            'A tenant-scoped query returned an organization from another tenant.'
        );
    }

    #[Test]
    public function a_scoped_lookup_by_primary_key_cannot_reach_another_tenants_record(): void
    {
        $alpha = $this->createWorkspace();
        $beta = $this->createWorkspace();

        app(TenantContext::class)->for($alpha['tenant']);

        $this->assertNull(
            Organization::query()->find($beta['organization']->getKey()),
            'Direct key lookup crossed the tenant boundary.'
        );
    }

    #[Test]
    public function a_new_record_is_stamped_with_the_bound_tenant(): void
    {
        $alpha = $this->createWorkspace();

        app(TenantContext::class)->for($alpha['tenant']);

        $organization = Organization::create([
            'name' => 'Second Workspace',
            'slug' => 'second-workspace',
            'status' => \App\Domains\Tenant\Enums\OrganizationStatus::Pending,
            'timezone' => 'Asia/Dhaka',
            'default_currency' => 'BDT',
        ]);

        $this->assertSame($alpha['tenant']->getKey(), $organization->tenant_id);
    }

    #[Test]
    public function tenant_context_refuses_an_organization_from_a_different_tenant(): void
    {
        $alpha = $this->createWorkspace();
        $beta = $this->createWorkspace();

        $this->expectException(\RuntimeException::class);

        app(TenantContext::class)->for($alpha['tenant'], $beta['organization']);
    }

    // ------------------------------------------------------------------
    // HTTP: cross-tenant read and write
    // ------------------------------------------------------------------

    #[Test]
    public function client_a_cannot_read_client_b_organization(): void
    {
        $alpha = $this->createWorkspace();
        $beta = $this->createWorkspace();

        // The admin detail route is the only organization-addressed route in
        // Phase 0; a client account must be refused it outright.
        $this->actingAs($alpha['user'])
            ->get(route('admin.clients.show', $beta['organization']->public_id))
            ->assertForbidden();
    }

    #[Test]
    public function a_client_cannot_switch_into_another_tenants_organization(): void
    {
        $alpha = $this->createWorkspace();
        $beta = $this->createWorkspace();

        $this->actingAs($alpha['user'])
            ->from(route('client.dashboard'))
            ->post(route('client.organization.switch'), [
                'organization' => $beta['organization']->public_id,
            ])
            ->assertSessionHasErrors('organization');

        // And the context did not move.
        $this->actingAs($alpha['user'])
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('currentOrganization.id', $alpha['organization']->public_id));
    }

    #[Test]
    public function a_client_can_switch_between_their_own_organizations(): void
    {
        $alpha = $this->createWorkspace();
        $second = Organization::factory()->forTenant($alpha['tenant'])->create();
        $this->addMembership($alpha['user'], $second);

        $this->actingAs($alpha['user'])
            ->from(route('client.dashboard'))
            ->post(route('client.organization.switch'), ['organization' => $second->public_id])
            ->assertSessionHasNoErrors();

        $this->actingAs($alpha['user'])
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('currentOrganization.id', $second->public_id));
    }

    // ------------------------------------------------------------------
    // Policies
    // ------------------------------------------------------------------

    #[Test]
    public function the_organization_policy_denies_access_across_tenants(): void
    {
        $alpha = $this->createWorkspace();
        $beta = $this->createWorkspace();

        $this->assertTrue($alpha['user']->can('view', $alpha['organization']));
        $this->assertFalse(
            $alpha['user']->can('view', $beta['organization']),
            'The organization policy allowed a cross-tenant read.'
        );
        $this->assertFalse($alpha['user']->can('update', $beta['organization']));
    }

    #[Test]
    public function agency_a_cannot_read_agency_b_clients(): void
    {
        $agencyA = Tenant::factory()->agency()->create();
        $agencyB = Tenant::factory()->agency()->create();

        $workspaceA = $this->createWorkspace('agency-owner', $agencyA);
        $clientOfB = Organization::factory()->forTenant($agencyB)->create();

        app(TenantContext::class)->for($agencyA, $workspaceA['organization']);

        $this->assertFalse(
            $workspaceA['user']->can('view', $clientOfB),
            'An agency owner could read a client belonging to another agency.'
        );

        $this->assertNull(
            Organization::query()->find($clientOfB->getKey()),
            'An agency-scoped query returned another agency\'s client.'
        );
    }

    #[Test]
    public function a_suspended_membership_does_not_grant_access(): void
    {
        $alpha = $this->createWorkspace();
        $other = Organization::factory()->forTenant($alpha['tenant'])->create();

        $this->addMembership(
            $alpha['user'],
            $other,
            \App\Domains\Tenant\Enums\MembershipStatus::Suspended,
        );

        $this->assertFalse(
            $alpha['user']->belongsToOrganization($other),
            'A suspended membership was treated as granting access.'
        );
        $this->assertFalse($alpha['user']->can('view', $other));
    }

    // ------------------------------------------------------------------
    // Audit isolation
    // ------------------------------------------------------------------

    #[Test]
    public function a_tenant_cannot_read_audit_entries_from_another_tenant(): void
    {
        $alpha = $this->createWorkspace();
        $beta = $this->createWorkspace();

        // Audit visibility is resolved against the organization in context,
        // exactly as it would be inside a request.
        app(TenantContext::class)->for($alpha['tenant'], $alpha['organization']);

        $ownEntry = AuditLog::create([
            'actor_id' => $alpha['user']->getKey(),
            'actor_type' => ActorType::User,
            'tenant_id' => $alpha['tenant']->getKey(),
            'action' => AuditAction::LoginSucceeded->value,
        ]);

        $foreignEntry = AuditLog::create([
            'actor_id' => $beta['user']->getKey(),
            'actor_type' => ActorType::User,
            'tenant_id' => $beta['tenant']->getKey(),
            'action' => AuditAction::LoginSucceeded->value,
        ]);

        $this->assertTrue($alpha['user']->can('view', $ownEntry));
        $this->assertFalse(
            $alpha['user']->can('view', $foreignEntry),
            'A tenant could read an audit entry belonging to another tenant.'
        );
    }

    // ------------------------------------------------------------------
    // Platform staff
    // ------------------------------------------------------------------

    #[Test]
    public function platform_staff_query_across_tenants_deliberately(): void
    {
        $this->createWorkspace();
        $this->createWorkspace();
        $admin = $this->createPlatformUser();

        $this->actingAs($admin)
            ->get(route('admin.clients.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('organizations.total', 2));
    }

    #[Test]
    public function a_platform_account_cannot_enter_the_client_application(): void
    {
        $admin = $this->createPlatformUser();

        $this->actingAs($admin)
            ->get(route('client.dashboard'))
            ->assertForbidden();
    }

    #[Test]
    public function a_user_without_an_organization_is_refused_the_client_application(): void
    {
        $tenant = Tenant::factory()->create();
        $orphan = User::factory()->forTenant($tenant)->create();

        // No membership, so no organization context can be resolved. The
        // request must fail closed rather than run unscoped.
        $this->actingAs($orphan)
            ->get(route('client.dashboard'))
            ->assertForbidden();
    }

    #[Test]
    public function a_user_in_a_suspended_tenant_is_refused(): void
    {
        $tenant = Tenant::factory()->suspended()->create();
        $organization = Organization::factory()->forTenant($tenant)->create();
        $user = User::factory()->memberOf($organization)->create();

        $this->actingAs($user)
            ->get(route('client.dashboard'))
            ->assertForbidden();
    }
}
