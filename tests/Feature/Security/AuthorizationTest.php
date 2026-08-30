<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * Role separation and privilege escalation (spec §68).
 *
 * The point of these is that holding one permission never implies another:
 * an accountant cannot approve, a support agent cannot move money, a campaign
 * manager cannot refund.
 */
final class AuthorizationTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    #[Test]
    public function a_client_accountant_cannot_approve_or_submit_campaigns(): void
    {
        $workspace = $this->createWorkspace('client-accountant');
        $organization = $workspace['organization'];

        $this->assertTrue($workspace['user']->hasPermissionTo('wallet.deposit', $organization));
        $this->assertFalse($workspace['user']->hasPermissionTo('campaigns.approve', $organization));
        $this->assertFalse($workspace['user']->hasPermissionTo('campaigns.submit', $organization));
    }

    #[Test]
    public function a_client_viewer_holds_read_permissions_only(): void
    {
        $workspace = $this->createWorkspace('client-viewer');
        $organization = $workspace['organization'];

        $this->assertTrue($workspace['user']->hasPermissionTo('campaigns.view', $organization));

        foreach (['campaigns.create', 'campaigns.submit', 'wallet.deposit', 'users.manage'] as $denied) {
            $this->assertFalse(
                $workspace['user']->hasPermissionTo($denied, $organization),
                "A viewer unexpectedly holds [{$denied}]."
            );
        }
    }

    #[Test]
    public function a_support_agent_cannot_modify_wallets(): void
    {
        $agent = $this->createPlatformUser('support-agent');

        $this->assertTrue($agent->hasPermissionTo('support.respond'));
        $this->assertFalse($agent->hasPermissionTo('wallet.adjust'));
        $this->assertFalse($agent->hasPermissionTo('wallet.refund'));
        $this->assertFalse($agent->hasPermissionTo('payments.verify'));
    }

    #[Test]
    public function a_campaign_manager_cannot_issue_refunds(): void
    {
        $manager = $this->createPlatformUser('campaign-manager');

        $this->assertTrue($manager->hasPermissionTo('campaigns.approve'));
        $this->assertFalse($manager->hasPermissionTo('wallet.refund'));
        $this->assertFalse($manager->hasPermissionTo('wallet.adjust'));
        $this->assertFalse($manager->hasPermissionTo('exchange_rates.manage'));
    }

    #[Test]
    public function a_client_user_cannot_reach_admin_routes(): void
    {
        $workspace = $this->createWorkspace('client-owner');

        foreach (['admin.dashboard', 'admin.clients.index', 'admin.audit.index'] as $route) {
            $this->actingAs($workspace['user'])
                ->get(route($route))
                ->assertForbidden();
        }
    }

    #[Test]
    public function an_admin_without_audit_permission_cannot_read_audit_logs(): void
    {
        // Operations admins deliberately do not hold audit.view.
        $admin = $this->createPlatformUser('operations-admin');

        $this->assertFalse($admin->hasPermissionTo('audit.view'));

        $this->actingAs($admin)
            ->get(route('admin.audit.index'))
            ->assertForbidden();
    }

    #[Test]
    public function a_tenant_user_cannot_see_or_grant_a_platform_role(): void
    {
        $workspace = $this->createWorkspace('client-owner');

        app(TenantContext::class)->for($workspace['tenant'], $workspace['organization']);

        /** @var Role $platformRole */
        $platformRole = Role::query()->whereNull('tenant_id')->where('slug', 'super-admin')->firstOrFail();

        $this->assertFalse(
            $workspace['user']->can('view', $platformRole),
            'A client owner could see a platform role.'
        );
        $this->assertFalse(
            $workspace['user']->can('assign', $platformRole),
            'A client owner could grant a platform role — this is privilege escalation.'
        );
    }

    #[Test]
    public function nobody_may_edit_a_system_role(): void
    {
        $admin = $this->createPlatformUser('super-admin');

        /** @var Role $systemRole */
        $systemRole = Role::query()->whereNull('tenant_id')->where('slug', 'client-owner')->firstOrFail();

        $this->assertTrue($systemRole->is_system);
        $this->assertFalse($admin->can('update', $systemRole));
        $this->assertFalse($admin->can('delete', $systemRole));
    }

    #[Test]
    public function a_user_cannot_change_their_own_roles_or_suspend_themselves(): void
    {
        $workspace = $this->createWorkspace('client-owner');
        $user = $workspace['user'];

        app(TenantContext::class)->for($workspace['tenant'], $workspace['organization']);

        $this->assertFalse(
            $user->can('manageRoles', $user),
            'A user could change their own role assignments.'
        );
        $this->assertFalse($user->can('suspend', $user));
    }

    #[Test]
    public function a_tenant_user_cannot_manage_a_user_from_another_tenant(): void
    {
        $alpha = $this->createWorkspace('client-owner');
        $beta = $this->createWorkspace('client-owner');

        app(TenantContext::class)->for($alpha['tenant'], $alpha['organization']);

        $this->assertFalse($alpha['user']->can('update', $beta['user']));
        $this->assertFalse($alpha['user']->can('manageRoles', $beta['user']));
    }

    #[Test]
    public function a_tenant_user_cannot_manage_a_platform_account(): void
    {
        $workspace = $this->createWorkspace('client-owner');
        $admin = $this->createPlatformUser();

        app(TenantContext::class)->for($workspace['tenant'], $workspace['organization']);

        $this->assertFalse($workspace['user']->can('view', $admin));
        $this->assertFalse($workspace['user']->can('update', $admin));
    }

    #[Test]
    public function an_organization_scoped_grant_does_not_apply_to_another_organization(): void
    {
        $workspace = $this->createWorkspace('client-owner');
        $second = Organization::factory()->forTenant($workspace['tenant'])->create();
        $this->addMembership($workspace['user'], $second);

        // The role was granted on the first organization only.
        $this->assertTrue($workspace['user']->hasPermissionTo('users.manage', $workspace['organization']));

        $workspace['user']->forgetCachedPermissions();

        $this->assertFalse(
            $workspace['user']->hasPermissionTo('users.manage', $second),
            'An organization-scoped grant leaked into a sibling organization.'
        );
    }

    #[Test]
    public function audit_entries_cannot_be_updated_or_deleted(): void
    {
        $workspace = $this->createWorkspace();

        $entry = AuditLog::create([
            'tenant_id' => $workspace['tenant']->getKey(),
            'action' => 'test.event',
        ]);

        $this->expectException(\RuntimeException::class);

        $entry->update(['action' => 'tampered']);
    }

    #[Test]
    public function an_audit_entry_cannot_be_deleted(): void
    {
        $workspace = $this->createWorkspace();

        $entry = AuditLog::create([
            'tenant_id' => $workspace['tenant']->getKey(),
            'action' => 'test.event',
        ]);

        $this->expectException(\RuntimeException::class);

        $entry->delete();
    }

    #[Test]
    public function guests_are_redirected_to_login_rather_than_served(): void
    {
        $this->get(route('client.dashboard'))->assertRedirect(route('login'));
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    #[Test]
    public function an_unverified_user_cannot_reach_the_application(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->memberOf($organization)->unverified()->create();

        $this->actingAs($user)
            ->get(route('client.dashboard'))
            ->assertRedirect(route('verification.notice'));
    }
}
