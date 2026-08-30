<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising;

use App\Domains\Advertising\Actions\ManagePoolMembership;
use App\Domains\Advertising\Actions\SaveAdAccountPool;
use App\Domains\Advertising\Enums\AdAccountHealth;
use App\Domains\Advertising\Enums\PoolStatus;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\SelectionStrategy;
use App\Domains\Advertising\Exceptions\AdAccountException;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Models\AdAccountPool;
use App\Domains\Advertising\Services\PoolEligibilityService;
use App\Domains\Advertising\Values\AllocationRules;
use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Wallet\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ad account pools and the rules they allocate by (spec §18, §19).
 */
final class AdAccountPoolTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_new_pool_starts_as_a_draft_and_is_not_allocatable(): void
    {
        $pool = $this->createPool();

        $this->assertSame(PoolStatus::Draft, $pool->status);
        $this->assertFalse($pool->isAllocatable());
    }

    #[Test]
    public function a_pool_refuses_an_account_from_another_provider(): void
    {
        $pool = AdAccountPool::factory()->provider(Provider::Meta)->create();
        $account = AdAccount::factory()->provider(Provider::Google)->create();

        $this->expectException(AdAccountException::class);

        app(ManagePoolMembership::class)->add($pool, $account, $this->operator());
    }

    #[Test]
    public function a_pool_refuses_an_account_in_another_currency(): void
    {
        $pool = AdAccountPool::factory()->currency('BDT')->create();
        $account = AdAccount::factory()->currency('USD')->create();

        $this->expectException(AdAccountException::class);

        app(ManagePoolMembership::class)->add($pool, $account, $this->operator());
    }

    #[Test]
    public function an_account_joins_a_pool_only_once_however_often_it_is_added(): void
    {
        $pool = AdAccountPool::factory()->create();
        $account = AdAccount::factory()->create();

        app(ManagePoolMembership::class)->add($pool, $account, $this->operator(), 2);
        app(ManagePoolMembership::class)->add($pool, $account, $this->operator(), 7);

        $this->assertSame(1, $pool->members()->count());
        $this->assertSame(7, $pool->members()->firstOrFail()->weight);
    }

    #[Test]
    public function the_database_refuses_a_second_membership_row(): void
    {
        $pool = AdAccountPool::factory()->create();
        $account = AdAccount::factory()->create();

        app(ManagePoolMembership::class)->add($pool, $account, $this->operator());

        $this->expectException(QueryException::class);

        DB::table('ad_account_pool_members')->insert([
            'ad_account_pool_id' => $pool->getKey(),
            'ad_account_id' => $account->getKey(),
            'weight' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function an_archived_pool_cannot_be_changed(): void
    {
        $pool = AdAccountPool::factory()->status(PoolStatus::Archived)->create();
        $account = AdAccount::factory()->create();

        $this->expectException(AdAccountException::class);

        app(ManagePoolMembership::class)->add($pool, $account, $this->operator());
    }

    #[Test]
    public function a_malformed_stored_rule_fails_loudly_rather_than_quietly_not_applying(): void
    {
        $pool = AdAccountPool::factory()->create();

        // Simulates a hand-edited row: the rule reader is the only path in, so
        // a rule nobody can parse must stop allocation rather than vanish.
        $pool->forceFill(['allocation_rules' => ['max_account_risk_score' => 400]])->save();

        $this->expectException(\InvalidArgumentException::class);

        $pool->refresh()->rules();
    }

    #[Test]
    public function an_unverified_client_is_refused_by_the_default_rules(): void
    {
        $pool = AdAccountPool::factory()->status(PoolStatus::Active)->create();
        $organization = $this->client(VerificationStatus::Pending);

        $failures = app(PoolEligibilityService::class)->clientFailures($pool, $organization);

        $this->assertNotEmpty($failures);
    }

    #[Test]
    public function a_verified_client_passes_the_default_rules(): void
    {
        $pool = AdAccountPool::factory()->status(PoolStatus::Active)->create();
        $organization = $this->client(VerificationStatus::Verified);

        $this->assertSame([], app(PoolEligibilityService::class)->clientFailures($pool, $organization));
    }

    #[Test]
    public function a_client_below_the_minimum_balance_is_refused(): void
    {
        $pool = AdAccountPool::factory()
            ->status(PoolStatus::Active)
            ->rules(AllocationRules::fromArray([
                'minimum_wallet_balance_minor' => 100_000,
            ]))
            ->create();

        $organization = $this->client(VerificationStatus::Verified);
        Wallet::factory()->forOrganization($organization)->create();

        $this->assertNotEmpty(app(PoolEligibilityService::class)->clientFailures($pool, $organization));
    }

    #[Test]
    public function a_country_rule_narrows_the_pool(): void
    {
        $pool = AdAccountPool::factory()
            ->status(PoolStatus::Active)
            ->rules(AllocationRules::fromArray(['allowed_countries' => ['bd']]))
            ->create();

        $inside = $this->client(VerificationStatus::Verified, country: 'BD');
        $outside = $this->client(VerificationStatus::Verified, country: 'SG');

        $service = app(PoolEligibilityService::class);

        $this->assertSame([], $service->clientFailures($pool, $inside));
        $this->assertNotEmpty($service->clientFailures($pool, $outside));
    }

    #[Test]
    public function an_unhealthy_account_is_left_out_when_the_pool_asks_for_healthy_ones(): void
    {
        $pool = AdAccountPool::factory()->status(PoolStatus::Active)->create();
        $account = AdAccount::factory()->health(AdAccountHealth::Critical)->create();

        app(ManagePoolMembership::class)->add($pool, $account, $this->operator());

        $this->assertNotEmpty(app(PoolEligibilityService::class)->accountFailures($pool, $account));
    }

    #[Test]
    public function an_account_without_enough_headroom_is_left_out(): void
    {
        $pool = AdAccountPool::factory()->status(PoolStatus::Active)->create();
        $account = AdAccount::factory()
            ->spent(4_900_000)
            ->create(['daily_spend_limit' => 5_000_000]);

        app(ManagePoolMembership::class)->add($pool, $account, $this->operator());

        $service = app(PoolEligibilityService::class);

        $this->assertSame([], $service->accountFailures($pool, $account, 50_000));
        $this->assertNotEmpty($service->accountFailures($pool, $account, 200_000));
    }

    #[Test]
    public function a_reserve_holds_back_headroom_the_pool_will_not_hand_out(): void
    {
        $pool = AdAccountPool::factory()
            ->status(PoolStatus::Active)
            ->rules(AllocationRules::fromArray(['reserve_headroom_minor' => 1_000_000]))
            ->create();

        $account = AdAccount::factory()->create(['daily_spend_limit' => 2_000_000]);
        app(ManagePoolMembership::class)->add($pool, $account, $this->operator());

        $service = app(PoolEligibilityService::class);

        $this->assertSame([], $service->accountFailures($pool, $account, 1_000_000));
        $this->assertNotEmpty($service->accountFailures($pool, $account, 1_000_001));
    }

    #[Test]
    public function eligible_accounts_are_empty_when_the_client_itself_is_refused(): void
    {
        $pool = AdAccountPool::factory()->status(PoolStatus::Active)->create();
        $account = AdAccount::factory()->create();
        app(ManagePoolMembership::class)->add($pool, $account, $this->operator());

        $organization = $this->client(VerificationStatus::Rejected);

        $this->assertSame([], app(PoolEligibilityService::class)->eligibleAccounts($pool, $organization));
    }

    #[Test]
    public function eligible_accounts_returns_the_members_that_qualify(): void
    {
        $pool = AdAccountPool::factory()->status(PoolStatus::Active)->create();
        $good = AdAccount::factory()->create();
        $unhealthy = AdAccount::factory()->health(AdAccountHealth::Critical)->create();

        app(ManagePoolMembership::class)->add($pool, $good, $this->operator());
        app(ManagePoolMembership::class)->add($pool, $unhealthy, $this->operator());

        $organization = $this->client(VerificationStatus::Verified);

        $eligible = app(PoolEligibilityService::class)->eligibleAccounts($pool, $organization);

        $this->assertCount(1, $eligible);
        $this->assertSame($good->getKey(), $eligible[0]->getKey());
    }

    #[Test]
    public function a_pool_cannot_be_revived_once_archived(): void
    {
        $pool = AdAccountPool::factory()->status(PoolStatus::Archived)->create();

        $this->expectException(AdAccountException::class);

        app(SaveAdAccountPool::class)->changeStatus($pool, PoolStatus::Active, $this->operator());
    }

    private function createPool(): AdAccountPool
    {
        return app(SaveAdAccountPool::class)->create(
            name: 'Meta BDT Standard',
            provider: Provider::Meta,
            currency: 'BDT',
            strategy: SelectionStrategy::LeastLoaded,
            rules: AllocationRules::default(),
            actor: $this->operator(),
        );
    }

    private function client(VerificationStatus $status, string $country = 'BD'): Organization
    {
        $organization = Organization::factory()->create(['country' => $country]);

        // Goes through the factory's own states so the fixture satisfies the
        // completeness constraint the profiles table enforces, rather than
        // writing a status the schema would reject in production.
        $factory = VerificationProfile::factory()->forOrganization($organization);

        $factory = match ($status) {
            VerificationStatus::Verified => $factory->verified(),
            VerificationStatus::Pending => $factory->submitted(),
            VerificationStatus::UnderReview => $factory->underReview(),
            VerificationStatus::RequiresInformation => $factory->requiresInformation(),
            default => $factory->state(fn (): array => [
                'status' => $status,
                'submitted_at' => now()->subDays(3),
                'reviewed_at' => now()->subDay(),
            ]),
        };

        $factory->create();

        return $organization;
    }

    private function operator(): User
    {
        return $this->operator ??= User::factory()->platform()->create();
    }

    private ?User $operator = null;
}
