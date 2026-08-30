<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Identity\Actions\AcceptInvitation;
use App\Domains\Identity\Actions\InviteTeamMember;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\TeamInvitation;
use App\Domains\Tenant\Enums\InvitationStatus;
use App\Domains\Tenant\Models\OrganizationInvitation;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * Team invitations (spec §82).
 *
 * The security properties that matter: an invitation cannot grant more than the
 * inviter holds, the stored row is not redeemable on its own, and a token is
 * spent exactly once.
 */
final class InvitationTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seedAccessControl();
    }

    #[Test]
    public function an_owner_can_invite_a_new_member(): void
    {
        ['organization' => $organization, 'user' => $owner] = $this->workspace();

        $invitation = app(InviteTeamMember::class)->handle(
            $organization,
            $owner,
            'newcomer@example.test',
            $this->role('client-marketer'),
        );

        $this->assertSame(InvitationStatus::Pending, $invitation->status);
        $this->assertSame($organization->tenant_id, $invitation->tenant_id);

        Notification::assertSentOnDemand(TeamInvitation::class);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::InvitationSent->value,
            'actor_id' => $owner->getKey(),
        ]);
    }

    #[Test]
    public function only_a_hash_of_the_token_is_stored(): void
    {
        ['organization' => $organization, 'user' => $owner] = $this->workspace();

        $invitation = app(InviteTeamMember::class)->handle(
            $organization,
            $owner,
            'newcomer@example.test',
            $this->role('client-marketer'),
        );

        $stored = $invitation->getAttributes()['token_hash'];

        $this->assertSame(64, strlen($stored), 'The stored value is not a SHA-256 hash.');

        // The hash cannot be replayed as a token: hashing it again gives
        // something different, so it will not match on lookup.
        $this->assertNull(OrganizationInvitation::forToken($stored)->first());
    }

    #[Test]
    public function accepting_creates_the_account_membership_and_role_together(): void
    {
        ['organization' => $organization, 'user' => $owner] = $this->workspace();

        $token = $this->inviteAndCaptureToken($organization, $owner, 'newcomer@example.test');

        $user = app(AcceptInvitation::class)->handle($token, [
            'name' => 'New Comer',
            'password' => 'Correct-Horse-Battery-9!',
        ]);

        $this->assertSame($organization->tenant_id, $user->tenant_id);
        $this->assertFalse($user->is_platform_user);
        $this->assertNotNull($user->email_verified_at, 'Receipt of the invitation proves the address.');
        $this->assertTrue($user->belongsToOrganization($organization));
        $this->assertTrue($user->hasPermissionTo('campaigns.create', $organization));
    }

    #[Test]
    public function a_token_cannot_be_redeemed_twice(): void
    {
        ['organization' => $organization, 'user' => $owner] = $this->workspace();

        $token = $this->inviteAndCaptureToken($organization, $owner, 'newcomer@example.test');

        app(AcceptInvitation::class)->handle($token, ['password' => 'Correct-Horse-Battery-9!']);

        $this->expectException(ValidationException::class);

        app(AcceptInvitation::class)->handle($token, ['password' => 'Correct-Horse-Battery-9!']);
    }

    #[Test]
    public function an_expired_invitation_is_refused(): void
    {
        ['organization' => $organization, 'user' => $owner] = $this->workspace();

        $token = $this->inviteAndCaptureToken($organization, $owner, 'newcomer@example.test');

        OrganizationInvitation::query()
            ->withoutGlobalScopes()
            ->update(['expires_at' => Carbon::now()->subDay()]);

        $this->expectException(ValidationException::class);

        app(AcceptInvitation::class)->handle($token, ['password' => 'Correct-Horse-Battery-9!']);
    }

    #[Test]
    public function a_revoked_invitation_cannot_be_redeemed(): void
    {
        ['organization' => $organization, 'user' => $owner] = $this->workspace();

        $token = $this->inviteAndCaptureToken($organization, $owner, 'newcomer@example.test');

        $invitation = OrganizationInvitation::query()->withoutGlobalScopes()->firstOrFail();

        $this->actingAs($owner)
            ->delete(route('client.team.invitations.revoke', $invitation->public_id))
            ->assertRedirect();

        $this->expectException(ValidationException::class);

        app(AcceptInvitation::class)->handle($token, ['password' => 'Correct-Horse-Battery-9!']);
    }

    #[Test]
    public function resending_invalidates_the_previous_token(): void
    {
        ['organization' => $organization, 'user' => $owner] = $this->workspace();

        $firstToken = $this->inviteAndCaptureToken($organization, $owner, 'newcomer@example.test');

        $invitation = OrganizationInvitation::query()->withoutGlobalScopes()->firstOrFail();
        app(InviteTeamMember::class)->resend($invitation, $owner);

        // A forwarded copy of the first email must no longer work.
        $this->expectException(ValidationException::class);

        app(AcceptInvitation::class)->handle($firstToken, ['password' => 'Correct-Horse-Battery-9!']);
    }

    #[Test]
    public function an_invitation_cannot_grant_more_than_the_inviter_holds(): void
    {
        // A marketer holds neither users.manage nor roles.manage, so they
        // cannot hand out an owner role — that would be escalation by proxy.
        ['organization' => $organization, 'user' => $marketer] = $this->workspace('client-marketer');

        $this->expectException(ValidationException::class);

        app(InviteTeamMember::class)->handle(
            $organization,
            $marketer,
            'escalation@example.test',
            $this->role('client-owner'),
        );
    }

    #[Test]
    public function a_client_cannot_invite_someone_into_a_platform_role(): void
    {
        ['organization' => $organization, 'user' => $owner] = $this->workspace();

        $this->expectException(ValidationException::class);

        app(InviteTeamMember::class)->handle(
            $organization,
            $owner,
            'admin@example.test',
            $this->role('super-admin'),
        );
    }

    #[Test]
    public function a_user_from_another_tenant_cannot_accept_an_invitation(): void
    {
        ['organization' => $organization, 'user' => $owner] = $this->workspace();
        $outsider = $this->createWorkspace('client-owner');

        $token = $this->inviteAndCaptureToken($organization, $owner, $outsider['user']->email);

        // The address matches, but the account already belongs elsewhere.
        $this->expectException(ValidationException::class);

        app(AcceptInvitation::class)->handle($token, [], $outsider['user']);
    }

    #[Test]
    public function a_signed_in_user_cannot_redeem_an_invitation_addressed_to_someone_else(): void
    {
        ['organization' => $organization, 'user' => $owner] = $this->workspace();

        $colleague = User::factory()->memberOf($organization)->create();

        $token = $this->inviteAndCaptureToken($organization, $owner, 'someone.else@example.test');

        $this->expectException(ValidationException::class);

        app(AcceptInvitation::class)->handle($token, [], $colleague);
    }

    #[Test]
    public function a_platform_account_cannot_join_a_client_organization(): void
    {
        ['organization' => $organization, 'user' => $owner] = $this->workspace();
        $admin = $this->createPlatformUser('super-admin');

        $token = $this->inviteAndCaptureToken($organization, $owner, $admin->email);

        $this->expectException(ValidationException::class);

        app(AcceptInvitation::class)->handle($token, [], $admin);
    }

    #[Test]
    public function inviting_an_existing_member_is_refused(): void
    {
        ['organization' => $organization, 'user' => $owner] = $this->workspace();

        $this->expectException(ValidationException::class);

        app(InviteTeamMember::class)->handle(
            $organization,
            $owner,
            $owner->email,
            $this->role('client-marketer'),
        );
    }

    #[Test]
    public function a_second_pending_invitation_to_the_same_address_is_refused(): void
    {
        ['organization' => $organization, 'user' => $owner] = $this->workspace();

        app(InviteTeamMember::class)->handle(
            $organization,
            $owner,
            'duplicate@example.test',
            $this->role('client-marketer'),
        );

        $this->expectException(ValidationException::class);

        app(InviteTeamMember::class)->handle(
            $organization,
            $owner,
            'duplicate@example.test',
            $this->role('client-viewer'),
        );
    }

    #[Test]
    public function the_acceptance_page_shows_nothing_for_an_unknown_token(): void
    {
        $this->get(route('invitations.show', ['token' => str_repeat('a', 64)]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/InvitationInvalid'));
    }

    /**
     * @return array{tenant: \App\Domains\Tenant\Models\Tenant, organization: \App\Domains\Tenant\Models\Organization, user: User}
     */
    private function workspace(string $roleSlug = 'client-owner'): array
    {
        $workspace = $this->createWorkspace($roleSlug);

        app(TenantContext::class)->for($workspace['tenant'], $workspace['organization']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }

    private function role(string $slug): Role
    {
        return Role::query()->whereNull('tenant_id')->where('slug', $slug)->firstOrFail();
    }

    /**
     * Sends an invitation and recovers the plaintext token from the queued
     * notification — the only place it exists.
     */
    private function inviteAndCaptureToken(
        \App\Domains\Tenant\Models\Organization $organization,
        User $inviter,
        string $email,
    ): string {
        app(InviteTeamMember::class)->handle($organization, $inviter, $email, $this->role('client-marketer'));

        $token = null;

        Notification::assertSentOnDemand(
            TeamInvitation::class,
            function (TeamInvitation $notification) use (&$token): bool {
                $reflection = new \ReflectionProperty($notification, 'token');
                $token = $reflection->getValue($notification);

                return true;
            },
        );

        $this->assertIsString($token);

        return $token;
    }
}
