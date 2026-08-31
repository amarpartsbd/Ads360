<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * The only way to get a first administrator into a production deployment.
 *
 * Worth testing properly because nothing else exercises it: it runs once, on a
 * server, by hand, and a mistake in it is found at the moment somebody is
 * locked out of a platform they have just installed.
 */
final class CreatePlatformAdminCommandTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    /** A password that satisfies the platform policy and is not in any breach list. */
    private const PASSWORD = 'Kh4l1shpur!Jetty#2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    #[Test]
    public function it_creates_a_platform_administrator_holding_the_named_role(): void
    {
        $this->artisan('ads:create-admin', [
            '--name' => 'Platform Owner',
            '--email' => 'owner@banik360.com',
            '--role' => 'super-admin',
        ])
            ->expectsQuestion('Password (hidden)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertSuccessful();

        $admin = User::query()->where('email', 'owner@banik360.com')->sole();

        $this->assertTrue($admin->is_platform_user);
        // Platform staff belong to no tenant, which is what keeps the global
        // scope from ever narrowing what they can see.
        $this->assertNull($admin->tenant_id);
        $this->assertSame(UserStatus::Active, $admin->status);
        $this->assertNotNull($admin->email_verified_at);

        // The grant is unscoped: a platform role attached to an organization
        // would only work inside that one.
        $this->assertDatabaseHas('role_user', [
            'user_id' => $admin->getKey(),
            'organization_id' => null,
            'tenant_id' => null,
        ]);

        $this->assertTrue($admin->hasPermissionTo('system.manage'));
    }

    #[Test]
    public function the_password_is_hashed_and_satisfies_the_platform_policy(): void
    {
        $this->artisan('ads:create-admin', [
            '--name' => 'Platform Owner',
            '--email' => 'owner@banik360.com',
        ])
            ->expectsQuestion('Password (hidden)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertSuccessful();

        $admin = User::query()->where('email', 'owner@banik360.com')->sole();

        $this->assertNotSame(self::PASSWORD, $admin->password);
        $this->assertTrue(password_verify(self::PASSWORD, $admin->password));
    }

    #[Test]
    public function a_weak_password_is_refused_and_creates_nobody(): void
    {
        $this->artisan('ads:create-admin', [
            '--name' => 'Platform Owner',
            '--email' => 'owner@banik360.com',
        ])
            ->expectsQuestion('Password (hidden)', 'password')
            ->expectsQuestion('Confirm password', 'password')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'owner@banik360.com']);
    }

    #[Test]
    public function a_mistyped_confirmation_is_refused(): void
    {
        $this->artisan('ads:create-admin', [
            '--name' => 'Platform Owner',
            '--email' => 'owner@banik360.com',
        ])
            ->expectsQuestion('Password (hidden)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD.'x')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'owner@banik360.com']);
    }

    #[Test]
    public function an_existing_address_is_refused_rather_than_overwritten(): void
    {
        User::factory()->create(['email' => 'owner@banik360.com']);

        $this->artisan('ads:create-admin', [
            '--name' => 'Platform Owner',
            '--email' => 'owner@banik360.com',
        ])
            ->expectsQuestion('Password (hidden)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertFailed();

        $this->assertSame(1, User::query()->where('email', 'owner@banik360.com')->count());
    }

    /**
     * A client role would authenticate but reach nothing, and the mistake would
     * only show up as an empty administration area.
     */
    #[Test]
    public function a_role_that_is_not_platform_scoped_is_refused(): void
    {
        $this->artisan('ads:create-admin', [
            '--name' => 'Platform Owner',
            '--email' => 'owner@banik360.com',
            '--role' => 'client-owner',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'owner@banik360.com']);
    }
}
