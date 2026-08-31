<?php

declare(strict_types=1);

namespace Tests\Feature\Campaign;

use App\Domains\Advertising\Enums\PoolStatus;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\SelectionStrategy;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Models\AdAccountPool;
use App\Domains\Campaign\Actions\AllocateAdAccount;
use App\Domains\Campaign\Enums\BudgetType;
use App\Domains\Campaign\Enums\CampaignObjective;
use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Exceptions\AllocationFailed;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Wallet\Services\WalletService;
use App\Support\Values\Money;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RunsConcurrently;
use Tests\TestCase;

/**
 * Allocation concurrency — the Phase 4 gate (spec §19, §56).
 *
 * Allocation reads which accounts have room and then writes a commitment to
 * one of them. Between those two steps another approval can take the same
 * capacity, and if both writes land the account is committed past a limit the
 * provider will enforce whether or not the platform agrees.
 *
 * These tests run genuinely parallel processes competing for the same scarce
 * headroom. DatabaseMigrations rather than RefreshDatabase, because a forked
 * child cannot see rows its parent has not committed.
 */
#[Group('concurrency')]
final class AllocationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;
    use RunsConcurrently;

    #[Test]
    public function two_campaigns_cannot_take_the_same_last_slot_of_headroom(): void
    {
        // Room for exactly one of the two campaigns.
        $account = $this->poolWithAccount(dailyLimit: 500_000);

        $campaigns = [
            $this->campaign('5000.00')->getKey(),
            $this->campaign('5000.00')->getKey(),
        ];

        $result = $this->runConcurrently(2, function (int $worker) use ($campaigns): bool {
            $campaign = Campaign::query()->withoutGlobalScopes()->findOrFail($campaigns[$worker]);

            try {
                DB::transaction(function () use ($campaign): void {
                    app(AllocateAdAccount::class)->handle($campaign);
                    $campaign->save();
                });

                return true;
            } catch (AllocationFailed) {
                return false;
            }
        });

        $this->assertSame(1, $result['succeeded'], 'Exactly one campaign should have been allocated.');
        $this->assertSame(1, $result['failed']);
        $this->assertSame([], $result['errors']);

        // And the account is committed once, not twice.
        $this->assertSame(500_000, $account->fresh()->committed_amount);
    }

    #[Test]
    public function many_campaigns_racing_one_account_never_over_commit_it(): void
    {
        // Room for exactly four of the eight campaigns.
        $account = $this->poolWithAccount(dailyLimit: 2_000_000);

        $campaigns = [];

        for ($index = 0; $index < 8; $index++) {
            $campaigns[] = $this->campaign('5000.00')->getKey();
        }

        $result = $this->runConcurrently(8, function (int $worker) use ($campaigns): bool {
            $campaign = Campaign::query()->withoutGlobalScopes()->findOrFail($campaigns[$worker]);

            try {
                DB::transaction(function () use ($campaign): void {
                    app(AllocateAdAccount::class)->handle($campaign);
                    $campaign->save();
                });

                return true;
            } catch (AllocationFailed) {
                return false;
            }
        });

        $this->assertSame(4, $result['succeeded']);
        $this->assertSame(4, $result['failed']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(2_000_000, $account->fresh()->committed_amount);
    }

    #[Test]
    public function capacity_spread_over_two_accounts_is_used_fully_and_not_exceeded(): void
    {
        // Two accounts, each with room for one campaign.
        $pool = $this->pool();
        $first = $this->accountIn($pool, 500_000);
        $second = $this->accountIn($pool, 500_000);

        $campaigns = [];

        for ($index = 0; $index < 4; $index++) {
            $campaigns[] = $this->campaign('5000.00')->getKey();
        }

        $result = $this->runConcurrently(4, function (int $worker) use ($campaigns): bool {
            $campaign = Campaign::query()->withoutGlobalScopes()->findOrFail($campaigns[$worker]);

            try {
                DB::transaction(function () use ($campaign): void {
                    app(AllocateAdAccount::class)->handle($campaign);
                    $campaign->save();
                });

                return true;
            } catch (AllocationFailed) {
                return false;
            }
        });

        // Both accounts are used — a shortlist that gave up after its first
        // locked candidate was taken would leave the second account idle.
        $this->assertSame(2, $result['succeeded']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(500_000, $first->fresh()->committed_amount);
        $this->assertSame(500_000, $second->fresh()->committed_amount);
    }

    #[Test]
    public function concurrent_approvals_hold_money_exactly_once_each(): void
    {
        $this->poolWithAccount(dailyLimit: 1_000_000_000);

        $campaigns = [
            $this->campaign('5000.00')->getKey(),
            $this->campaign('5000.00')->getKey(),
        ];

        $result = $this->runConcurrently(2, function (int $worker) use ($campaigns): bool {
            $campaign = Campaign::query()->withoutGlobalScopes()->findOrFail($campaigns[$worker]);

            DB::transaction(function () use ($campaign): void {
                app(AllocateAdAccount::class)->handle($campaign);
                $campaign->save();
            });

            return true;
        });

        $this->assertSame(2, $result['succeeded']);
        $this->assertSame([], $result['errors']);

        // Each campaign records its own share, and the account holds the sum.
        $total = Campaign::query()->withoutGlobalScopes()->sum('account_commitment');

        $this->assertSame(1_000_000, (int) $total);
        $this->assertSame(1_000_000, (int) AdAccount::query()->sum('committed_amount'));
    }

    // ------------------------------------------------------------------

    private function poolWithAccount(int $dailyLimit): AdAccount
    {
        return $this->accountIn($this->pool(), $dailyLimit);
    }

    private function pool(): AdAccountPool
    {
        return AdAccountPool::factory()
            ->status(PoolStatus::Active)
            ->strategy(SelectionStrategy::HighestPriority)
            ->create(['provider' => Provider::Meta, 'currency' => 'BDT']);
    }

    private function accountIn(AdAccountPool $pool, int $dailyLimit): AdAccount
    {
        $account = AdAccount::factory()->create([
            'daily_spend_limit' => $dailyLimit,
            'monthly_spend_limit' => $dailyLimit * 30,
        ]);

        $pool->accounts()->attach($account->getKey(), ['weight' => 1]);

        return $account;
    }

    /**
     * A campaign belonging to a verified, funded client. Built with the
     * factory rather than the full builder: these tests are about the write
     * race in allocation, not about readiness.
     */
    private function campaign(string $budget): Campaign
    {
        $organization = Organization::factory()->create([
            'default_currency' => 'BDT',
            'country' => 'BD',
        ]);

        VerificationProfile::factory()->forOrganization($organization)->verified()->create();

        $wallet = app(WalletService::class)->walletFor($organization, 'BDT');
        app(WalletService::class)->deposit($wallet, Money::of('100000.00', 'BDT'), 'Test funding');

        return Campaign::factory()->forOrganization($organization)->create([
            'status' => CampaignStatus::PendingReview,
            'provider' => Provider::Meta,
            'objective' => CampaignObjective::Traffic,
            'budget_type' => BudgetType::Lifetime,
            'budget_amount' => Money::of($budget, 'BDT')->minorUnits,
            'submitted_at' => now(),
        ]);
    }
}
