<?php

declare(strict_types=1);

namespace Tests\Feature\Agency;

use App\Domains\Agency\Services\AgencyDirectory;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Tenant\Enums\MembershipStatus;
use App\Domains\Tenant\Models\Tenant;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * How far an agency user reaches (spec §42, §68).
 *
 * The hierarchy is Platform → Agency → Agency Client, and every test here is
 * about one of its two edges: an agency owner reaching down to every client of
 * their own agency, and nobody reaching sideways into another agency.
 */
final class AgencyReachTest extends TestCase
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
    public function an_agency_owner_reaches_a_client_they_have_no_membership_in(): void
    {
        $agency = $this->createAgencyWorkspace();
        $client = $this->createAgencyClient($agency['tenant'], 'Riverside Cafe');

        $owner = $agency['user'];

        $this->assertFalse(
            $owner->belongsToOrganization($client),
            'The fixture should not have given the owner a membership.'
        );

        // The tenant-wide grant is what reaches, and it reaches clients created
        // long after the owner joined.
        $this->assertTrue($owner->actsAcrossTenant());
        $this->assertTrue($owner->canReachOrganization($client));
    }

    #[Test]
    public function an_agency_owner_can_act_inside_a_client_workspace(): void
    {
        $agency = $this->createAgencyWorkspace();
        $client = $this->createAgencyClient($agency['tenant']);

        app(TenantContext::class)->for($agency['tenant'], $client);

        $campaign = Campaign::factory()->forOrganization($client)->create();

        $this->assertTrue($agency['user']->can('view', $campaign));
        $this->assertTrue($agency['user']->can('view', $client));
    }

    #[Test]
    public function a_client_owner_is_not_given_agency_reach_by_this_change(): void
    {
        // client-owner is an ORGANIZATION-scoped role however senior it sounds.
        $workspace = $this->createWorkspace('client-owner');
        $sibling = $this->createAgencyClient($workspace['tenant'], 'Another workspace');

        $this->assertFalse($workspace['user']->actsAcrossTenant());
        $this->assertFalse(
            $workspace['user']->canReachOrganization($sibling),
            'An organization-scoped role reached an organization it was never granted.'
        );
    }

    #[Test]
    public function agency_a_cannot_reach_a_client_of_agency_b(): void
    {
        $agencyA = $this->createAgencyWorkspace();
        $agencyB = $this->createAgencyWorkspace();

        $clientOfB = $this->createAgencyClient($agencyB['tenant'], 'Not yours');

        // The failure §42 names outright.
        $this->assertFalse($agencyA['user']->canReachOrganization($clientOfB));

        app(TenantContext::class)->for($agencyA['tenant'], $agencyA['organization']);

        $this->assertFalse($agencyA['user']->can('view', $clientOfB));
    }

    #[Test]
    public function agency_staff_reach_only_the_clients_they_are_assigned_to(): void
    {
        $agency = $this->createAgencyWorkspace();
        $assigned = $this->createAgencyClient($agency['tenant'], 'Assigned');
        $other = $this->createAgencyClient($agency['tenant'], 'Not assigned');

        $staff = \App\Domains\Identity\Models\User::factory()
            ->memberOf($agency['organization'])
            ->create();

        $this->addMembership($staff, $assigned);
        $this->grantRole($staff, 'agency-manager', $assigned);

        $this->assertFalse($staff->actsAcrossTenant());
        $this->assertTrue($staff->canReachOrganization($assigned));

        // A media buyer on one client cannot read another client's spend.
        $this->assertFalse($staff->canReachOrganization($other));
    }

    #[Test]
    public function a_revoked_membership_stops_reaching(): void
    {
        $agency = $this->createAgencyWorkspace();
        $client = $this->createAgencyClient($agency['tenant']);

        $staff = \App\Domains\Identity\Models\User::factory()
            ->memberOf($agency['organization'])
            ->create();

        $this->addMembership($staff, $client, MembershipStatus::Revoked);

        $this->assertFalse($staff->canReachOrganization($client));
    }

    #[Test]
    public function the_roster_shows_an_owner_every_client_and_staff_only_theirs(): void
    {
        $agency = $this->createAgencyWorkspace();
        $first = $this->createAgencyClient($agency['tenant'], 'Alpha');
        $second = $this->createAgencyClient($agency['tenant'], 'Beta');

        $staff = \App\Domains\Identity\Models\User::factory()
            ->memberOf($agency['organization'])
            ->create();
        $this->addMembership($staff, $first);
        $this->grantRole($staff, 'agency-manager', $first);

        app(TenantContext::class)->for($agency['tenant'], $agency['organization']);

        $directory = app(AgencyDirectory::class);

        $ownerSees = $directory->roster($agency['tenant'], $agency['user'])
            ->map(fn ($summary): string => $summary->name)->all();

        $staffSees = $directory->roster($agency['tenant'], $staff)
            ->map(fn ($summary): string => $summary->name)->all();

        $this->assertSame(['Alpha', 'Beta'], $ownerSees);
        $this->assertSame(['Alpha'], $staffSees);
    }

    #[Test]
    public function the_roster_never_lists_the_agencys_own_workspace_as_a_client(): void
    {
        $agency = $this->createAgencyWorkspace();
        $this->createAgencyClient($agency['tenant'], 'A real client');

        app(TenantContext::class)->for($agency['tenant'], $agency['organization']);

        $names = app(AgencyDirectory::class)
            ->roster($agency['tenant'], $agency['user'])
            ->map(fn ($summary): string => $summary->name)
            ->all();

        $this->assertSame(['A real client'], $names);
    }

    #[Test]
    public function a_roster_lookup_cannot_be_pointed_at_another_agencys_client(): void
    {
        $agencyA = $this->createAgencyWorkspace();
        $agencyB = $this->createAgencyWorkspace();
        $clientOfB = $this->createAgencyClient($agencyB['tenant'], 'Not yours');

        app(TenantContext::class)->for($agencyA['tenant'], $agencyA['organization']);

        // A real identifier, from a real organization, belonging to someone
        // else. It has to find nothing rather than resolve and be refused
        // later (spec §5).
        $this->assertNull(
            app(AgencyDirectory::class)->clientFor($agencyA['tenant'], $agencyA['user'], $clientOfB->public_id),
        );
    }

    #[Test]
    public function a_direct_client_tenant_has_no_roster(): void
    {
        $workspace = $this->createWorkspace('client-owner');

        app(TenantContext::class)->for($workspace['tenant'], $workspace['organization']);

        /** @var Tenant $tenant */
        $tenant = $workspace['tenant'];

        $this->assertFalse($tenant->type->managesClients());
    }
}
