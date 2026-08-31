<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Identity\Actions\ManageTeamMember;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\MembershipStatus;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * Changing a member's standing in an organization (spec §82).
 */
final class TeamManagementTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    #[Test]
    public function an_owner_can_change_a_members_roles(): void
    {
        [$organization, $owner, $member] = $this->teamOfTwo();

        app(ManageTeamMember::class)->changeRoles(
            $organization,
            $member,
            [$this->role('client-accountant')],
            $owner,
        );

        $member->forgetCachedPermissions();

        $this->assertTrue($member->hasPermissionTo('wallet.deposit', $organization));
        $this->assertFalse($member->hasPermissionTo('campaigns.create', $organization));

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::RoleAssigned->value,
            'actor_id' => $owner->getKey(),
        ]);
    }

    #[Test]
    public function a_role_change_does_not_touch_other_organizations(): void
    {
        [$organization, $owner, $member] = $this->teamOfTwo();

        $second = Organization::factory()->forTenant($organization->tenant)->create();
        $this->addMembership($member, $second);
        $this->grantRole($member, 'client-owner', $second);

        app(ManageTeamMember::class)->changeRoles(
            $organization,
            $member,
            [$this->role('client-viewer')],
            $owner,
        );

        $member->forgetCachedPermissions();

        // Downgraded here, untouched there.
        $this->assertFalse($member->hasPermissionTo('campaigns.create', $organization));

        $member->forgetCachedPermissions();
        $this->assertTrue($member->hasPermissionTo('campaigns.create', $second));
    }

    #[Test]
    public function nobody_may_grant_a_role_they_cannot_assign(): void
    {
        $workspace = $this->createWorkspace('client-marketer');
        app(TenantContext::class)->for($workspace['tenant'], $workspace['organization']);
        $this->actingAs($workspace['user']);

        $member = User::factory()->memberOf($workspace['organization'])->create();

        $this->expectException(ValidationException::class);

        app(ManageTeamMember::class)->changeRoles(
            $workspace['organization'],
            $member,
            [$this->role('super-admin')],
            $workspace['user'],
        );
    }

    #[Test]
    public function a_member_must_keep_at_least_one_role(): void
    {
        [$organization, $owner, $member] = $this->teamOfTwo();

        $this->expectException(ValidationException::class);

        app(ManageTeamMember::class)->changeRoles($organization, $member, [], $owner);
    }

    #[Test]
    public function suspending_a_member_stops_their_access(): void
    {
        [$organization, $owner, $member] = $this->teamOfTwo();

        app(ManageTeamMember::class)->suspend($organization, $member, $owner);

        $this->assertFalse(
            $member->fresh()?->belongsToOrganization($organization),
            'A suspended member still had access.'
        );

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->getKey(),
            'user_id' => $member->getKey(),
            'status' => MembershipStatus::Suspended->value,
        ]);
    }

    #[Test]
    public function a_suspended_member_can_be_reinstated(): void
    {
        [$organization, $owner, $member] = $this->teamOfTwo();

        app(ManageTeamMember::class)->suspend($organization, $member, $owner);
        app(ManageTeamMember::class)->reinstate($organization, $member, $owner);

        $this->assertTrue($member->fresh()?->belongsToOrganization($organization));
    }

    #[Test]
    public function removing_a_member_revokes_their_roles_here_only(): void
    {
        [$organization, $owner, $member] = $this->teamOfTwo();

        app(ManageTeamMember::class)->remove($organization, $member, $owner);

        $this->assertDatabaseMissing('organization_user', [
            'organization_id' => $organization->getKey(),
            'user_id' => $member->getKey(),
        ]);

        $this->assertDatabaseMissing('role_user', [
            'user_id' => $member->getKey(),
            'organization_id' => $organization->getKey(),
        ]);

        // The account itself survives: they may belong to other organizations.
        $this->assertNotNull($member->fresh());
    }

    #[Test]
    public function the_last_administrator_cannot_be_removed(): void
    {
        $workspace = $this->createWorkspace('client-owner');
        app(TenantContext::class)->for($workspace['tenant'], $workspace['organization']);

        $other = User::factory()->memberOf($workspace['organization'])->create();
        $this->grantRole($other, 'client-owner', $workspace['organization']);

        // `other` can act, but removing the only remaining administrator after
        // that would strand the organization.
        app(ManageTeamMember::class)->remove($workspace['organization'], $workspace['user'], $other);

        $this->expectException(ValidationException::class);

        app(ManageTeamMember::class)->remove($workspace['organization'], $other, $other);
    }

    #[Test]
    public function the_last_administrator_cannot_be_downgraded(): void
    {
        [$organization, $owner] = $this->teamOfTwo();

        $second = User::factory()->memberOf($organization)->create();
        $this->grantRole($second, 'client-admin', $organization);

        app(ManageTeamMember::class)->changeRoles(
            $organization,
            $second,
            [$this->role('client-viewer')],
            $owner,
        );

        // The owner is now the only member who can manage the team.
        $this->expectException(ValidationException::class);

        app(ManageTeamMember::class)->changeRoles(
            $organization,
            $owner,
            [$this->role('client-viewer')],
            $owner,
        );
    }

    #[Test]
    public function a_member_cannot_suspend_or_remove_themselves(): void
    {
        [$organization, $owner] = $this->teamOfTwo();

        try {
            app(ManageTeamMember::class)->suspend($organization, $owner, $owner);
            $this->fail('An owner suspended their own membership.');
        } catch (ValidationException) {
            // expected
        }

        $this->expectException(ValidationException::class);
        app(ManageTeamMember::class)->remove($organization, $owner, $owner);
    }

    #[Test]
    public function a_member_from_another_tenant_cannot_be_acted_on_over_http(): void
    {
        [$organization, $owner] = $this->teamOfTwo();
        $outsider = $this->createWorkspace('client-owner');

        $this->actingAs($owner)
            ->delete(route('client.team.members.remove', $outsider['user']->public_id))
            ->assertForbidden();

        // Asserted against the row rather than through the model: the bound
        // context here belongs to the acting tenant, which would scope the
        // membership query to the wrong tenant and mask the result.
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $outsider['organization']->getKey(),
            'user_id' => $outsider['user']->getKey(),
            'status' => MembershipStatus::Active->value,
        ]);
    }

    #[Test]
    public function a_viewer_cannot_reach_team_management_actions(): void
    {
        $workspace = $this->createWorkspace('client-viewer');
        $member = User::factory()->memberOf($workspace['organization'])->create();

        $this->actingAs($workspace['user'])
            ->post(route('client.team.invite'), [
                'email' => 'someone@example.test',
                'role' => $this->role('client-viewer')->public_id,
            ])
            ->assertForbidden();

        $this->actingAs($workspace['user'])
            ->delete(route('client.team.members.remove', $member->public_id))
            ->assertForbidden();
    }

    /**
     * The membership check behind every team action, over HTTP.
     *
     * Worth its own test because the check reads the membership *pivot*, and
     * the two branches — active only, and any standing — are the only place in
     * this controller where that happens.
     */
    #[Test]
    public function team_actions_check_the_membership_pivot(): void
    {
        [$organization, $owner, $member] = $this->teamOfTwo();

        $this->actingAs($owner)
            ->post(route('client.team.members.suspend', $member->public_id))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->getKey(),
            'user_id' => $member->getKey(),
            'status' => MembershipStatus::Suspended->value,
        ]);

        // Suspending again asks the same question of a membership that is no
        // longer active, so the request is not found rather than repeated.
        $this->actingAs($owner)
            ->post(route('client.team.members.suspend', $member->public_id))
            ->assertNotFound();

        // Reinstating accepts any standing, which is the other branch.
        $this->actingAs($owner)
            ->post(route('client.team.members.reinstate', $member->public_id))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->getKey(),
            'user_id' => $member->getKey(),
            'status' => MembershipStatus::Active->value,
        ]);
    }

    /**
     * @return array{0: Organization, 1: User, 2: User}
     */
    private function teamOfTwo(): array
    {
        $workspace = $this->createWorkspace('client-owner');
        app(TenantContext::class)->for($workspace['tenant'], $workspace['organization']);
        $this->actingAs($workspace['user']);

        $member = User::factory()->memberOf($workspace['organization'])->create();
        $this->grantRole($member, 'client-marketer', $workspace['organization']);

        return [$workspace['organization'], $workspace['user'], $member];
    }

    private function role(string $slug): Role
    {
        return Role::query()->whereNull('tenant_id')->where('slug', $slug)->firstOrFail();
    }
}
