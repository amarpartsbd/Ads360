<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising;

use App\Domains\Advertising\DTOs\ProviderAccountState;
use App\Domains\Advertising\Enums\AdAccountBillingStatus;
use App\Domains\Advertising\Enums\AdAccountHealth;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Jobs\CheckAdAccountHealth;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Notifications\AdAccountNeedsAttention;
use App\Domains\Advertising\Providers\MockAdvertisingProvider;
use App\Domains\Advertising\Services\AdAccountHealthService;
use App\Domains\Advertising\Services\ProviderManager;
use App\Domains\Audit\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * Managed ad account health monitoring (spec §20).
 */
final class AdAccountHealthTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    #[Test]
    public function a_clean_provider_report_leaves_the_account_healthy(): void
    {
        $account = AdAccount::factory()->health(AdAccountHealth::Unknown)->create();

        $health = app(AdAccountHealthService::class)->check($account);

        $this->assertSame(AdAccountHealth::Healthy, $health);
        $this->assertSame(0, $account->fresh()->consecutive_failures);
        $this->assertNotNull($account->fresh()->last_synced_at);
    }

    #[Test]
    public function a_figure_the_provider_did_not_report_is_left_alone(): void
    {
        $account = AdAccount::factory()->spent(1_200_000)->create();

        $this->provider()->willReportAccountState(new ProviderAccountState(
            externalAccountId: $account->external_account_id,
            status: 'ACTIVE',
            billingStatus: 'OK',
            // Deliberately silent about spend. Writing zero here would report
            // the account as idle and hand it straight back out (spec §87).
            spentTodayMinor: null,
        ));

        app(AdAccountHealthService::class)->check($account);

        $this->assertSame(1_200_000, $account->fresh()->current_daily_spend);
    }

    #[Test]
    public function a_reported_spend_figure_replaces_the_stored_one(): void
    {
        $account = AdAccount::factory()->create();

        $this->provider()->willReportAccountState(new ProviderAccountState(
            externalAccountId: $account->external_account_id,
            status: 'ACTIVE',
            billingStatus: 'OK',
            spentTodayMinor: 2_500_000,
            spentThisMonthMinor: 9_000_000,
        ));

        app(AdAccountHealthService::class)->check($account);
        $account->refresh();

        $this->assertSame(2_500_000, $account->current_daily_spend);
        $this->assertSame(9_000_000, $account->current_monthly_spend);
        // Half of a 5,000,000 daily limit, well under the warning threshold.
        $this->assertSame(AdAccountHealth::Healthy, $account->health_status);
    }

    #[Test]
    public function an_account_close_to_its_daily_limit_is_reported_as_degraded(): void
    {
        config()->set('platform.advertising.health.utilisation_warning_percent', 80);
        config()->set('platform.advertising.health.utilisation_critical_percent', 95);

        $account = AdAccount::factory()->create(['daily_spend_limit' => 5_000_000]);

        $this->provider()->willReportAccountState(new ProviderAccountState(
            externalAccountId: $account->external_account_id,
            status: 'ACTIVE',
            billingStatus: 'OK',
            spentTodayMinor: 4_200_000,
        ));

        app(AdAccountHealthService::class)->check($account);

        $this->assertSame(AdAccountHealth::Degraded, $account->fresh()->health_status);
    }

    #[Test]
    public function an_account_at_its_daily_limit_is_reported_at_risk(): void
    {
        config()->set('platform.advertising.health.utilisation_critical_percent', 95);

        $account = AdAccount::factory()->create(['daily_spend_limit' => 5_000_000]);

        $this->provider()->willReportAccountState(new ProviderAccountState(
            externalAccountId: $account->external_account_id,
            status: 'ACTIVE',
            billingStatus: 'OK',
            spentTodayMinor: 4_900_000,
        ));

        app(AdAccountHealthService::class)->check($account);
        $account->refresh();

        $this->assertSame(AdAccountHealth::AtRisk, $account->health_status);
        $this->assertFalse($account->isAllocatable());
    }

    #[Test]
    public function a_billing_problem_puts_the_account_at_risk_and_stops_allocation(): void
    {
        $account = AdAccount::factory()->create();

        $this->provider()->willReportAccountState(new ProviderAccountState(
            externalAccountId: $account->external_account_id,
            status: 'ACTIVE',
            billingStatus: 'PAYMENT_FAILED',
        ));

        app(AdAccountHealthService::class)->check($account);
        $account->refresh();

        $this->assertSame(AdAccountBillingStatus::PaymentFailed, $account->billing_status);
        $this->assertSame(AdAccountHealth::AtRisk, $account->health_status);
        $this->assertFalse($account->isAllocatable());
    }

    #[Test]
    public function an_unrecognised_billing_word_stays_unknown_rather_than_being_guessed_healthy(): void
    {
        $account = AdAccount::factory()->create();

        $this->provider()->willReportAccountState(new ProviderAccountState(
            externalAccountId: $account->external_account_id,
            status: 'ACTIVE',
            billingStatus: 'SOMETHING_NEW_FROM_THE_PROVIDER',
        ));

        app(AdAccountHealthService::class)->check($account);

        $this->assertSame(AdAccountBillingStatus::Unknown, $account->fresh()->billing_status);
    }

    #[Test]
    public function a_disapproval_is_critical(): void
    {
        $account = AdAccount::factory()->create();

        $this->provider()->willReportAccountState(new ProviderAccountState(
            externalAccountId: $account->external_account_id,
            status: 'ACTIVE',
            billingStatus: 'OK',
            disapprovalReason: 'Policy review',
        ));

        app(AdAccountHealthService::class)->check($account);

        $this->assertSame(AdAccountHealth::Critical, $account->fresh()->health_status);
    }

    #[Test]
    public function one_transient_failure_does_not_change_the_verdict(): void
    {
        $account = AdAccount::factory()->create();

        $this->provider()->willFail('accountState', ProviderUnavailable::transient(Provider::Meta, 'timeout'));

        $health = app(AdAccountHealthService::class)->check($account);
        $account->refresh();

        $this->assertSame(AdAccountHealth::Healthy, $health);
        $this->assertSame(1, $account->consecutive_failures);
        $this->assertNotNull($account->last_error);
    }

    #[Test]
    public function a_run_of_transient_failures_eventually_does(): void
    {
        $account = AdAccount::factory()->create();

        $this->provider()->willFail('accountState', ProviderUnavailable::transient(Provider::Meta, 'timeout'));

        $service = app(AdAccountHealthService::class);

        $service->check($account);
        $this->assertSame(AdAccountHealth::Healthy, $account->fresh()->health_status);

        $service->check($account->fresh());
        $this->assertSame(AdAccountHealth::AtRisk, $account->fresh()->health_status);
    }

    #[Test]
    public function a_definite_refusal_is_critical_at_once(): void
    {
        $account = AdAccount::factory()->create();

        $this->provider()->willFail('accountState', ProviderUnavailable::refused(Provider::Meta, 'account closed'));

        $this->assertSame(AdAccountHealth::Critical, app(AdAccountHealthService::class)->check($account));
    }

    #[Test]
    public function an_error_message_stored_on_the_account_is_the_client_safe_one(): void
    {
        $account = AdAccount::factory()->create();

        $exception = ProviderUnavailable::refused(Provider::Meta, 'OAuthException #190 token invalid');
        $this->provider()->willFail('accountState', $exception);

        app(AdAccountHealthService::class)->check($account);

        $this->assertStringNotContainsString('OAuthException', (string) $account->fresh()->last_error);
    }

    #[Test]
    public function a_health_change_notifies_platform_staff_and_nobody_else(): void
    {
        Notification::fake();

        $operator = $this->createPlatformUser();

        $account = AdAccount::factory()->create();
        $this->provider()->willFail('accountState', ProviderUnavailable::refused(Provider::Meta, 'closed'));

        app(AdAccountHealthService::class)->check($account);

        Notification::assertSentTo($operator, AdAccountNeedsAttention::class);
    }

    #[Test]
    public function a_health_change_is_notified_once_not_on_every_sweep(): void
    {
        Notification::fake();

        $operator = $this->createPlatformUser();

        $account = AdAccount::factory()->create();
        $this->provider()->willFail('accountState', ProviderUnavailable::refused(Provider::Meta, 'closed'));

        $service = app(AdAccountHealthService::class);
        $service->check($account);
        $service->check($account->fresh());

        Notification::assertSentToTimes($operator, AdAccountNeedsAttention::class, 1);
    }

    #[Test]
    public function a_health_change_is_audited(): void
    {
        $account = AdAccount::factory()->create();
        $this->provider()->willFail('accountState', ProviderUnavailable::refused(Provider::Meta, 'closed'));

        app(AdAccountHealthService::class)->check($account);

        $entry = AuditLog::query()->where('action', 'ad_account.health_changed')->firstOrFail();

        $this->assertSame('HEALTHY', $entry->context['from']);
        $this->assertSame('CRITICAL', $entry->context['to']);
    }

    #[Test]
    public function an_account_nobody_has_checked_for_hours_is_not_reported_as_healthy(): void
    {
        $account = AdAccount::factory()->create([
            'last_synced_at' => Carbon::now()->subDay(),
        ]);

        $this->assertTrue(app(AdAccountHealthService::class)->isStale($account));
        $this->assertSame(AdAccountHealth::Unknown, app(AdAccountHealthService::class)->deriveHealth($account));
    }

    #[Test]
    public function the_sweep_queues_one_job_per_account_in_service(): void
    {
        Queue::fake();

        AdAccount::factory()->count(3)->create();
        AdAccount::factory()->suspended()->create();

        $this->artisan('ads:check-ad-accounts')->assertSuccessful();

        Queue::assertPushed(CheckAdAccountHealth::class, 3);
    }

    #[Test]
    public function the_job_does_not_fail_when_the_account_has_gone(): void
    {
        $account = AdAccount::factory()->create();
        $id = $account->getKey();
        $account->forceDelete();

        // Retiring an account between scheduling and running is ordinary, and
        // must not leave a failed job behind for someone to investigate.
        (new CheckAdAccountHealth($id))->handle(app(AdAccountHealthService::class));

        $this->addToAssertionCount(1);
    }

    private function provider(): MockAdvertisingProvider
    {
        $adapter = app(ProviderManager::class)->for(Provider::Meta);

        $this->assertInstanceOf(MockAdvertisingProvider::class, $adapter);

        return $adapter;
    }
}
