<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising;

use App\Domains\Advertising\Enums\AdAccountStatus;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Models\AdAccountPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * Who may see and change the advertising infrastructure (spec §17, §18, §68).
 *
 * The inventory is shared between clients, so the important assertions here are
 * the negative ones: a client must not be able to reach it at all, and staff
 * without the right permission must not be able to change it.
 */
final class AdInfrastructureAccessTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    #[Test]
    public function a_client_cannot_reach_the_inventory(): void
    {
        $workspace = $this->createWorkspace();

        $this->actingAs($workspace['user'])
            ->get(route('admin.ad-accounts.index'))
            ->assertForbidden();
    }

    #[Test]
    public function a_client_cannot_reach_a_single_account(): void
    {
        $workspace = $this->createWorkspace();
        $account = AdAccount::factory()->create();

        $this->actingAs($workspace['user'])
            ->get(route('admin.ad-accounts.show', $account))
            ->assertForbidden();
    }

    #[Test]
    public function a_client_cannot_reach_the_pools(): void
    {
        $workspace = $this->createWorkspace();

        $this->actingAs($workspace['user'])
            ->get(route('admin.ad-account-pools.index'))
            ->assertForbidden();
    }

    #[Test]
    public function an_operator_can_see_the_inventory(): void
    {
        AdAccount::factory()->count(2)->create();

        $this->actingAs($this->createPlatformUser('operations-admin'))
            ->get(route('admin.ad-accounts.index'))
            ->assertOk();
    }

    #[Test]
    public function an_operations_admin_cannot_register_an_account(): void
    {
        // Registering is a provisioning action; operations staff run accounts
        // rather than create them.
        $this->actingAs($this->createPlatformUser('operations-admin'))
            ->post(route('admin.ad-accounts.store'), [
                'provider' => 'META',
                'external_account_id' => 'act_1',
                'name' => 'Test',
                'currency' => 'BDT',
                'timezone' => 'Asia/Dhaka',
            ])
            ->assertForbidden();

        $this->assertSame(0, AdAccount::query()->count());
    }

    #[Test]
    public function a_super_admin_can_register_an_account(): void
    {
        $this->actingAs($this->createPlatformUser())
            ->post(route('admin.ad-accounts.store'), [
                'provider' => 'META',
                'external_account_id' => 'act_9001',
                'name' => 'New Managed Account',
                'currency' => 'BDT',
                'timezone' => 'Asia/Dhaka',
                'daily_spend_limit' => '25000.00',
            ])
            ->assertRedirect();

        $account = AdAccount::query()->firstOrFail();

        $this->assertSame(AdAccountStatus::PendingSetup, $account->status);
        // Converted server-side from the decimal the operator typed (Rule 8).
        $this->assertSame(2_500_000, $account->daily_spend_limit);
    }

    #[Test]
    public function only_a_pool_manager_may_create_a_pool(): void
    {
        $payload = [
            'name' => 'Meta BDT',
            'provider' => 'META',
            'currency' => 'BDT',
            'selection_strategy' => 'LEAST_LOADED',
        ];

        $this->actingAs($this->createPlatformUser('operations-admin'))
            ->post(route('admin.ad-account-pools.store'), $payload)
            ->assertForbidden();

        $this->actingAs($this->createPlatformUser())
            ->post(route('admin.ad-account-pools.store'), $payload)
            ->assertRedirect();

        $this->assertSame(1, AdAccountPool::query()->count());
    }

    #[Test]
    public function a_rule_the_value_object_refuses_comes_back_as_a_validation_error(): void
    {
        $this->actingAs($this->createPlatformUser())
            ->post(route('admin.ad-account-pools.store'), [
                'name' => 'Bad rules',
                'provider' => 'META',
                'currency' => 'BDT',
                'selection_strategy' => 'LEAST_LOADED',
                'rules' => ['allowed_countries' => ['BANGLADESH']],
            ])
            ->assertSessionHasErrors();

        $this->assertSame(0, AdAccountPool::query()->count());
    }

    #[Test]
    public function an_unknown_currency_is_refused_before_anything_is_written(): void
    {
        $this->actingAs($this->createPlatformUser())
            ->post(route('admin.ad-accounts.store'), [
                'provider' => 'META',
                'external_account_id' => 'act_2',
                'name' => 'Test',
                'currency' => 'XYZ',
                'timezone' => 'Asia/Dhaka',
            ])
            ->assertSessionHasErrors('currency');

        $this->assertSame(0, AdAccount::query()->count());
    }
}
