<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising;

use App\Domains\Advertising\Actions\ChangeAdAccountStatus;
use App\Domains\Advertising\Actions\RegisterAdAccount;
use App\Domains\Advertising\Actions\UpdateAdAccount;
use App\Domains\Advertising\Enums\AdAccountBillingStatus;
use App\Domains\Advertising\Enums\AdAccountHealth;
use App\Domains\Advertising\Enums\AdAccountStatus;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Exceptions\AdAccountException;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The managed ad account inventory (spec §17).
 */
final class AdAccountInventoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_registered_account_starts_pending_and_is_not_allocatable(): void
    {
        $account = $this->register();

        $this->assertSame(AdAccountStatus::PendingSetup, $account->status);
        $this->assertSame(AdAccountHealth::Unknown, $account->health_status);
        $this->assertSame(AdAccountBillingStatus::Unknown, $account->billing_status);
        $this->assertFalse($account->isAllocatable());
    }

    #[Test]
    public function registering_the_same_provider_account_twice_is_refused(): void
    {
        $this->register(external: 'act_555');

        $this->expectException(AdAccountException::class);

        $this->register(external: 'act_555');
    }

    #[Test]
    public function the_database_refuses_a_duplicate_even_without_the_action(): void
    {
        $first = AdAccount::factory()->create(['external_account_id' => 'act_dup']);

        $this->expectException(QueryException::class);

        // Bypasses the action entirely: the unique index has to hold on its
        // own, because a race can put two inserts past any read-then-write.
        DB::table('ad_accounts')->insert([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'provider' => $first->provider->value,
            'external_account_id' => 'act_dup',
            'name' => 'Duplicate',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'status' => AdAccountStatus::Active->value,
            'health_status' => AdAccountHealth::Healthy->value,
            'billing_status' => AdAccountBillingStatus::Current->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_retired_account_cannot_come_back(): void
    {
        $account = AdAccount::factory()->create(['status' => AdAccountStatus::Retired]);

        $this->expectException(AdAccountException::class);

        app(ChangeAdAccountStatus::class)->handle($account, AdAccountStatus::Active, $this->operator());
    }

    #[Test]
    public function suspending_an_account_records_why_and_stops_allocation(): void
    {
        $account = AdAccount::factory()->create();

        app(ChangeAdAccountStatus::class)->handle(
            $account,
            AdAccountStatus::Suspended,
            $this->operator(),
            'Provider restriction',
        );

        $account->refresh();

        $this->assertSame(AdAccountStatus::Suspended, $account->status);
        $this->assertSame('Provider restriction', $account->disabled_reason);
        $this->assertNotNull($account->disabled_at);
        $this->assertFalse($account->isAllocatable());
    }

    #[Test]
    public function a_status_change_is_audited_with_both_ends_of_the_move(): void
    {
        $account = AdAccount::factory()->create();

        app(ChangeAdAccountStatus::class)->handle($account, AdAccountStatus::Paused, $this->operator());

        $entry = AuditLog::query()->where('action', 'ad_account.status_changed')->firstOrFail();

        $this->assertSame('ACTIVE', $entry->context['from']);
        $this->assertSame('PAUSED', $entry->context['to']);
    }

    #[Test]
    public function a_spend_limit_cannot_be_set_below_what_is_already_spent(): void
    {
        $account = AdAccount::factory()->spent(300_000)->committed(100_000)->create();

        $this->expectException(AdAccountException::class);

        app(UpdateAdAccount::class)->handle(
            $account,
            ['daily_spend_limit' => 350_000],
            $this->operator(),
        );
    }

    #[Test]
    public function spend_counters_cannot_be_written_through_the_update_action(): void
    {
        $account = AdAccount::factory()->create();

        app(UpdateAdAccount::class)->handle(
            $account,
            ['name' => 'Renamed', 'current_daily_spend' => 999_999],
            $this->operator(),
        );

        $account->refresh();

        $this->assertSame('Renamed', $account->name);
        $this->assertSame(0, $account->current_daily_spend);
    }

    #[Test]
    public function the_database_refuses_a_negative_spend_figure(): void
    {
        $account = AdAccount::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('ad_accounts')
            ->where('id', $account->getKey())
            ->update(['current_daily_spend' => -1]);
    }

    #[Test]
    public function headroom_accounts_for_both_spend_and_commitments(): void
    {
        $account = AdAccount::factory()
            ->spent(1_000_000)
            ->committed(500_000)
            ->create(['daily_spend_limit' => 5_000_000]);

        $this->assertSame(3_500_000, $account->dailyHeadroom()?->minorUnits);
        $this->assertSame(30, $account->dailyUtilisationPercent());
    }

    #[Test]
    public function an_account_without_limits_reports_no_headroom_figure(): void
    {
        $account = AdAccount::factory()->withoutLimits()->create();

        $this->assertNull($account->dailyHeadroom());
        $this->assertNull($account->dailyUtilisationPercent());
    }

    #[Test]
    public function the_allocatable_scope_matches_the_model_predicate(): void
    {
        AdAccount::factory()->create();
        AdAccount::factory()->suspended()->create();
        AdAccount::factory()->billing(AdAccountBillingStatus::PaymentFailed)->create();
        AdAccount::factory()->health(AdAccountHealth::Critical)->create();

        $allocatable = AdAccount::query()->allocatable()->get();

        $this->assertCount(1, $allocatable);

        foreach (AdAccount::all() as $account) {
            $this->assertSame(
                $allocatable->contains($account->getKey()),
                $account->isAllocatable(),
                "Scope and predicate disagree for {$account->public_id}.",
            );
        }
    }

    #[Test]
    public function describing_an_account_carries_no_internal_key(): void
    {
        $account = AdAccount::factory()->create();

        $this->assertArrayNotHasKey('id', $account->describe());
        $this->assertSame($account->public_id, $account->describe()['public_id']);
    }

    private function register(string $external = 'act_100200300'): AdAccount
    {
        return app(RegisterAdAccount::class)->handle(
            provider: Provider::Meta,
            externalAccountId: $external,
            name: 'Managed Account',
            currency: 'BDT',
            timezone: 'Asia/Dhaka',
            actor: $this->operator(),
        );
    }

    private function operator(): User
    {
        return $this->operator ??= User::factory()->platform()->create();
    }

    private ?User $operator = null;
}
