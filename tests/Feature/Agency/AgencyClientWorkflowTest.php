<?php

declare(strict_types=1);

namespace Tests\Feature\Agency;

use App\Domains\Agency\Actions\AssignStaffToClient;
use App\Domains\Agency\Actions\CreateAgencyClient;
use App\Domains\Agency\Exceptions\AgencyException;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\MembershipStatus;
use App\Domains\Tenant\Enums\OrganizationStatus;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * The agency's own screens (spec §42).
 *
 * The important assertions here are the negative ones: what an agency cannot
 * do to another agency's client, and what it cannot do to its own.
 */
final class AgencyClientWorkflowTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
        config()->set('platform.features.agency_module', true);
    }

    #[Test]
    public function an_agency_owner_sees_their_roster(): void
    {
        $agency = $this->createAgencyWorkspace();
        $this->createAgencyClient($agency['tenant'], 'Riverside Cafe');

        $this->actingAs($agency['user'])
            ->get(route('client.clients.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Agency/Index')
                ->where('clients.0.name', 'Riverside Cafe')
                ->where('totals.clients', 1));
    }

    #[Test]
    public function a_direct_client_has_no_agency_screens_at_all(): void
    {
        $workspace = $this->createWorkspace('client-owner');

        // Not a 403: for a direct client this route describes nothing that
        // exists.
        $this->actingAs($workspace['user'])
            ->get(route('client.clients.index'))
            ->assertNotFound();
    }

    #[Test]
    public function the_screens_are_closed_when_the_module_is_off(): void
    {
        config()->set('platform.features.agency_module', false);

        $agency = $this->createAgencyWorkspace();

        $this->actingAs($agency['user'])
            ->get(route('client.clients.index'))
            ->assertNotFound();
    }

    #[Test]
    public function an_agency_adds_a_client_that_starts_unverified(): void
    {
        $agency = $this->createAgencyWorkspace();

        $this->actingAs($agency['user'])
            ->post(route('client.clients.store'), [
                'name' => 'Riverside Cafe',
                'contact_email' => 'owner@riverside.test',
            ])
            ->assertRedirect();

        $client = Organization::query()
            ->withoutGlobalScopes()
            ->where('name', 'Riverside Cafe')
            ->firstOrFail();

        $this->assertSame($agency['tenant']->getKey(), $client->tenant_id);
        $this->assertFalse($client->isHouseAccount());

        /*
         * An agency vouching for its own client would be the business being
         * checked signing off on the check (§11).
         */
        $this->assertSame(OrganizationStatus::Pending, $client->status);
    }

    #[Test]
    public function an_agency_cannot_open_another_agencys_client(): void
    {
        $agencyA = $this->createAgencyWorkspace();
        $agencyB = $this->createAgencyWorkspace();
        $clientOfB = $this->createAgencyClient($agencyB['tenant'], 'Not yours');

        // A real identifier for a real organization. It must find nothing —
        // a refusal would confirm the organization exists (spec §5).
        $this->actingAs($agencyA['user'])
            ->get(route('client.clients.show', ['client' => $clientOfB->public_id]))
            ->assertNotFound();

        $this->actingAs($agencyA['user'])
            ->post(route('client.clients.open', ['client' => $clientOfB->public_id]))
            ->assertNotFound();
    }

    #[Test]
    public function opening_a_client_puts_the_agency_in_that_workspace(): void
    {
        $agency = $this->createAgencyWorkspace();
        $client = $this->createAgencyClient($agency['tenant'], 'Riverside Cafe');

        $this->actingAs($agency['user'])
            ->post(route('client.clients.open', ['client' => $client->public_id]))
            ->assertRedirect(route('client.dashboard'));

        // The same session key the workspace switcher uses: there is no second,
        // weaker path into a client's data.
        $this->assertSame(
            $client->getKey(),
            session(\App\Http\Middleware\ResolveTenantContext::SESSION_KEY),
        );
    }

    #[Test]
    public function assigning_staff_grants_reach_to_that_client_only(): void
    {
        $agency = $this->createAgencyWorkspace();
        $assigned = $this->createAgencyClient($agency['tenant'], 'Assigned');
        $other = $this->createAgencyClient($agency['tenant'], 'Other');

        $staff = User::factory()->memberOf($agency['organization'])->create();

        $this->actingAs($agency['user'])
            ->post(route('client.clients.staff.assign', ['client' => $assigned->public_id]), [
                'user' => $staff->public_id,
                'role' => 'agency-manager',
            ])
            ->assertRedirect();

        $staff->refresh()->forgetCachedPermissions();

        $this->assertTrue($staff->canReachOrganization($assigned));
        $this->assertFalse($staff->canReachOrganization($other));
    }

    #[Test]
    public function a_tenant_wide_role_cannot_be_assigned_to_a_single_client(): void
    {
        $agency = $this->createAgencyWorkspace();
        $client = $this->createAgencyClient($agency['tenant']);
        $staff = User::factory()->memberOf($agency['organization'])->create();

        /*
         * Assigning an owner to one client would read as narrowing their
         * access while actually widening it, because that role already spans
         * the agency.
         */
        $this->actingAs($agency['user'])
            ->post(route('client.clients.staff.assign', ['client' => $client->public_id]), [
                'user' => $staff->public_id,
                'role' => 'agency-owner',
            ])
            ->assertSessionHasErrors('role');
    }

    #[Test]
    public function staff_from_another_agency_cannot_be_assigned(): void
    {
        $agencyA = $this->createAgencyWorkspace();
        $agencyB = $this->createAgencyWorkspace();

        $client = $this->createAgencyClient($agencyA['tenant']);
        $stranger = User::factory()->memberOf($agencyB['organization'])->create();

        $this->actingAs($agencyA['user'])
            ->post(route('client.clients.staff.assign', ['client' => $client->public_id]), [
                'user' => $stranger->public_id,
                'role' => 'agency-manager',
            ])
            ->assertNotFound();
    }

    #[Test]
    public function removing_staff_revokes_the_membership_rather_than_erasing_it(): void
    {
        $agency = $this->createAgencyWorkspace();
        $client = $this->createAgencyClient($agency['tenant']);
        $staff = User::factory()->memberOf($agency['organization'])->create();

        $assign = app(AssignStaffToClient::class);
        $assign->handle($agency['tenant'], $client, $staff, 'agency-manager', $agency['user']);

        $this->actingAs($agency['user'])
            ->delete(route('client.clients.staff.remove', [
                'client' => $client->public_id,
                'member' => $staff->public_id,
            ]))
            ->assertRedirect();

        $staff->refresh()->forgetCachedPermissions();

        $this->assertFalse($staff->canReachOrganization($client));

        // The row survives: audit entries written while they had access point
        // at it (§62).
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $client->getKey(),
            'user_id' => $staff->getKey(),
            'status' => MembershipStatus::Revoked->value,
        ]);
    }

    #[Test]
    public function removing_staff_from_one_client_leaves_their_other_clients_alone(): void
    {
        $agency = $this->createAgencyWorkspace();
        $first = $this->createAgencyClient($agency['tenant'], 'First');
        $second = $this->createAgencyClient($agency['tenant'], 'Second');
        $staff = User::factory()->memberOf($agency['organization'])->create();

        $assign = app(AssignStaffToClient::class);
        $assign->handle($agency['tenant'], $first, $staff, 'agency-manager');
        $assign->handle($agency['tenant'], $second, $staff, 'agency-manager');

        $assign->remove($agency['tenant'], $first, $staff);

        $staff->refresh()->forgetCachedPermissions();

        $this->assertFalse($staff->canReachOrganization($first));
        $this->assertTrue(
            $staff->canReachOrganization($second),
            'Removing someone from one client stripped their access to another.'
        );
    }

    #[Test]
    public function the_agencys_own_workspace_is_not_a_client_to_assign_staff_to(): void
    {
        $agency = $this->createAgencyWorkspace();
        $staff = User::factory()->memberOf($agency['organization'])->create();

        $this->expectException(AgencyException::class);

        app(AssignStaffToClient::class)
            ->handle($agency['tenant'], $agency['organization'], $staff, 'agency-manager');
    }

    #[Test]
    public function a_direct_client_tenant_cannot_be_given_clients(): void
    {
        $workspace = $this->createWorkspace('client-owner');

        $this->expectException(AgencyException::class);

        app(CreateAgencyClient::class)->handle($workspace['tenant'], ['name' => 'Nope']);
    }

    #[Test]
    public function two_agencies_may_both_have_a_client_with_the_same_name(): void
    {
        $agencyA = $this->createAgencyWorkspace();
        $agencyB = $this->createAgencyWorkspace();

        $create = app(CreateAgencyClient::class);

        $first = $create->handle($agencyA['tenant'], ['name' => 'Riverside Cafe']);
        $second = $create->handle($agencyB['tenant'], ['name' => 'Riverside Cafe']);

        // Slugs are unique within an agency, not globally: neither agency
        // should have to know what the other's clients are called.
        $this->assertSame('riverside-cafe', $first->slug);
        $this->assertSame('riverside-cafe', $second->slug);
    }
}
