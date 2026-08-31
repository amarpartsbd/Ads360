<?php

declare(strict_types=1);

namespace Tests\Feature\Agency;

use App\Domains\Agency\Actions\AssignAgencyPlan;
use App\Domains\Agency\Actions\CreateAgencyClient;
use App\Domains\Agency\Actions\ProvisionAgency;
use App\Domains\Agency\Exceptions\AgencyException;
use App\Domains\Billing\Enums\PricingScope;
use App\Domains\Billing\Models\PricingPlan;
use App\Domains\Billing\Services\PricingEngine;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\TenantType;
use App\Domains\Tenant\Models\Tenant;
use App\Support\Values\Money;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * Provisioning an agency and pricing it (spec §41, §42, §36).
 */
final class AgencyAdministrationTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
        $this->seed(FinanceSeeder::class);
        config()->set('platform.features.agency_module', true);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function provisionInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Demo Media',
            'type' => TenantType::Agency->value,
            'billing_email' => 'billing@demo-media.test',
            'owner_name' => 'Agency Owner',
            'owner_email' => 'owner@demo-media.test',
            'owner_password' => 'correct-horse-battery-staple-8',
            'owner_password_confirmation' => 'correct-horse-battery-staple-8',
        ], $overrides);
    }

    #[Test]
    public function provisioning_builds_the_whole_hierarchy_in_one_go(): void
    {
        $staff = $this->createPlatformUser();

        $result = app(ProvisionAgency::class)->handle($this->provisionInput(), $staff);

        $this->assertSame(TenantType::Agency, $result['tenant']->type);
        $this->assertTrue($result['organization']->isHouseAccount());

        // The grant that spans the agency: no organization on the pivot.
        $this->assertTrue($result['owner']->actsAcrossTenant());
        $this->assertDatabaseHas('role_user', [
            'user_id' => $result['owner']->getKey(),
            'organization_id' => null,
            'tenant_id' => $result['tenant']->getKey(),
        ]);
    }

    #[Test]
    public function a_provisioned_owner_reaches_clients_created_afterwards(): void
    {
        $result = app(ProvisionAgency::class)->handle($this->provisionInput());

        $client = app(CreateAgencyClient::class)
            ->handle($result['tenant'], ['name' => 'Riverside Cafe']);

        // The whole reason the grant carries no organization.
        $this->assertTrue($result['owner']->canReachOrganization($client));
    }

    #[Test]
    public function an_agency_cannot_be_provisioned_while_the_module_is_off(): void
    {
        config()->set('platform.features.agency_module', false);

        $this->expectException(AgencyException::class);

        app(ProvisionAgency::class)->handle($this->provisionInput());
    }

    #[Test]
    public function a_direct_client_type_cannot_be_provisioned_as_an_agency(): void
    {
        $this->expectException(AgencyException::class);

        app(ProvisionAgency::class)->handle(
            $this->provisionInput(['type' => TenantType::DirectClient->value]),
        );
    }

    #[Test]
    public function only_platform_staff_can_provision_an_agency(): void
    {
        $agency = $this->createAgencyWorkspace();

        // An agency owner has clients.create — for their own clients. It must
        // not let them mint another agency.
        $this->actingAs($agency['user'])
            ->post(route('admin.agencies.store'), $this->provisionInput())
            ->assertForbidden();
    }

    #[Test]
    public function assigning_a_schedule_prices_every_client_of_that_agency(): void
    {
        $agency = $this->createAgencyWorkspace();
        $client = $this->createAgencyClient($agency['tenant'], 'Riverside Cafe');

        /** @var PricingPlan $template */
        $template = PricingPlan::query()->where('name', 'Agency standard')->firstOrFail();

        app(AssignAgencyPlan::class)->handle($agency['tenant'], $template);

        $resolved = app(PricingEngine::class)->planFor($client);

        // The tenant plan beats the platform default for every organization of
        // the tenant, including ones the assignment never named (§36).
        $this->assertSame(PricingScope::Tenant, $resolved->scope);
        $this->assertSame($agency['tenant']->getKey(), $resolved->tenant_id);
    }

    #[Test]
    public function the_agency_rate_actually_changes_what_a_client_is_charged(): void
    {
        $agency = $this->createAgencyWorkspace();
        $client = $this->createAgencyClient($agency['tenant']);

        $engine = app(PricingEngine::class);
        $budget = Money::of('100000.00', 'BDT');

        $atDefault = $engine->price($client, $budget)->total;

        /** @var PricingPlan $template */
        $template = PricingPlan::query()->where('name', 'Agency standard')->firstOrFail();
        app(AssignAgencyPlan::class)->handle($agency['tenant'], $template);

        $atAgencyRate = $engine->price($client, $budget)->total;

        // 5.5% rather than 7.5%: the agency brings its own clients.
        $this->assertTrue(
            $atAgencyRate->lessThan($atDefault),
            'The agency schedule did not reduce what the client is charged.'
        );
    }

    #[Test]
    public function a_schedule_is_copied_rather_than_shared_between_agencies(): void
    {
        $first = $this->createAgencyWorkspace();
        $second = $this->createAgencyWorkspace();

        /** @var PricingPlan $template */
        $template = PricingPlan::query()->where('name', 'Agency standard')->firstOrFail();

        $assign = app(AssignAgencyPlan::class);
        $a = $assign->handle($first['tenant'], $template);
        $b = $assign->handle($second['tenant'], $template);

        // Two rows, not one: changing one agency's terms must never silently
        // rewrite the other's (§36).
        $this->assertNotSame($a->getKey(), $b->getKey());
        $this->assertSame($first['tenant']->getKey(), $a->tenant_id);
        $this->assertSame($second['tenant']->getKey(), $b->tenant_id);
        $this->assertCount($template->rules->count(), $a->rules);
    }

    #[Test]
    public function reassigning_deactivates_the_previous_plan_rather_than_deleting_it(): void
    {
        $agency = $this->createAgencyWorkspace();

        $assign = app(AssignAgencyPlan::class);

        /** @var PricingPlan $standard */
        $standard = PricingPlan::query()->where('name', 'Agency standard')->firstOrFail();
        /** @var PricingPlan $preferred */
        $preferred = PricingPlan::query()->where('name', 'Reseller preferred')->firstOrFail();

        $first = $assign->handle($agency['tenant'], $standard);
        $second = $assign->handle($agency['tenant'], $preferred);

        // Priced transactions point at the old row and have to keep explaining
        // themselves (§62).
        $this->assertDatabaseHas('pricing_plans', ['id' => $first->getKey(), 'is_active' => false]);
        $this->assertTrue($second->fresh()->is_active);
        $this->assertSame($second->getKey(), $assign->current($agency['tenant'])?->getKey());
    }

    #[Test]
    public function one_agencys_negotiated_plan_cannot_be_assigned_to_another(): void
    {
        $first = $this->createAgencyWorkspace();
        $second = $this->createAgencyWorkspace();

        /** @var PricingPlan $template */
        $template = PricingPlan::query()->where('name', 'Agency standard')->firstOrFail();

        $negotiated = app(AssignAgencyPlan::class)->handle($first['tenant'], $template);

        // Moving commercial terms between two customers of the platform
        // through what looks like a dropdown.
        $this->expectException(AgencyException::class);

        app(AssignAgencyPlan::class)->handle($second['tenant'], $negotiated);
    }

    #[Test]
    public function an_agency_can_read_its_own_schedule_and_not_change_it(): void
    {
        $agency = $this->createAgencyWorkspace();

        /** @var PricingPlan $template */
        $template = PricingPlan::query()->where('name', 'Agency standard')->firstOrFail();
        app(AssignAgencyPlan::class)->handle($agency['tenant'], $template);

        $this->actingAs($agency['user'])
            ->get(route('client.clients.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('pricing.isAgencyRate', true));

        // There is no client-side route that writes pricing at all.
        $this->actingAs($agency['user'])
            ->post(route('admin.agencies.pricing', ['agency' => $agency['tenant']->public_id]), [
                'plan' => $template->public_id,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function platform_staff_see_agencies_and_their_client_counts(): void
    {
        $agency = $this->createAgencyWorkspace();
        $this->createAgencyClient($agency['tenant'], 'Riverside Cafe');

        $staff = $this->createPlatformUser();

        $this->actingAs($staff)
            ->get(route('admin.agencies.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Agencies/Index')
                ->where('agencies.0.clients', 1));
    }

    #[Test]
    public function a_direct_client_tenant_never_appears_in_the_agency_list(): void
    {
        $this->createWorkspace('client-owner');
        $staff = $this->createPlatformUser();

        $this->actingAs($staff)
            ->get(route('admin.agencies.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('agencies', []));
    }

    #[Test]
    public function an_agency_user_cannot_reach_the_admin_agency_screens(): void
    {
        $agency = $this->createAgencyWorkspace();

        /** @var Tenant $other */
        $other = Tenant::factory()->agency()->create();

        $this->actingAs($agency['user'])
            ->get(route('admin.agencies.show', ['agency' => $other->public_id]))
            ->assertForbidden();
    }

    #[Test]
    public function a_provisioned_owner_must_still_verify_their_email(): void
    {
        $result = app(ProvisionAgency::class)->handle($this->provisionInput());

        /** @var User $owner */
        $owner = $result['owner'];

        /*
         * Provisioning does not confirm that whoever typed the address
         * controls it. They may sign in — the account is not disabled — but
         * the `verified` middleware keeps them out of the application until
         * they have proved the address is theirs.
         */
        $this->assertSame(UserStatus::PendingVerification, $owner->status);
        $this->assertNull($owner->email_verified_at);

        $this->actingAs($owner)
            ->get(route('client.clients.index'))
            ->assertRedirect(route('verification.notice'));
    }
}
