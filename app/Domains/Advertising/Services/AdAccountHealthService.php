<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Services;

use App\Domains\Advertising\DTOs\ProviderAccountState;
use App\Domains\Advertising\Enums\AdAccountBillingStatus;
use App\Domains\Advertising\Enums\AdAccountHealth;
use App\Domains\Advertising\Enums\ProviderCapability;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Notifications\AdAccountNeedsAttention;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the managed inventory's health current (spec §17, §20).
 *
 * Two ideas run through this class.
 *
 * The first is that a provider's silence is not a verdict. A request that
 * times out says the network had a bad moment, not that the account is ill, so
 * a transient failure moves a counter and nothing else. Only a run of them is
 * treated as a symptom.
 *
 * The second is that null is not zero. §87 forbids assuming any provider
 * reports any particular figure, so a field the provider left out leaves the
 * stored value alone rather than overwriting it with zero — which would read
 * as "spent nothing today" and hand the account out as if it were idle.
 */
final class AdAccountHealthService
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Fetch the provider's view of one account and record what it says.
     *
     * Returns the health it settled on so a sweep can summarise without
     * re-reading every row.
     */
    public function check(AdAccount $account): AdAccountHealth
    {
        if (! $this->providers->isAvailable($account->provider)) {
            // The provider is switched off platform-wide. That is a decision
            // about us, not an observation about the account.
            return $account->health_status;
        }

        $adapter = $this->providers->for($account->provider);

        if (! $adapter->supports(ProviderCapability::ManagedAdAccounts)) {
            return $account->health_status;
        }

        try {
            // Accounts we were let into by a client are read through that
            // grant; the rest are read with the platform's own credentials.
            $state = $adapter->accountState(
                $account->external_account_id,
                $account->provider_connection_id === null
                    ? null
                    : $account->connection()->withoutGlobalScopes()->first(),
            );
        } catch (ProviderUnavailable $exception) {
            return $this->recordFailure($account, $exception);
        }

        return $this->record($account, $state);
    }

    /**
     * Apply a provider observation to an account.
     *
     * Public so the same path serves a scheduled sweep, a webhook, and an
     * operator pressing refresh — there is one place where provider figures
     * become stored figures.
     */
    public function record(AdAccount $account, ProviderAccountState $state): AdAccountHealth
    {
        $previous = $account->health_status;
        $before = AuditRecorder::snapshot($account);

        $billing = $this->billingFrom($state);

        DB::transaction(function () use ($account, $state, $billing): void {
            // Only figures the provider actually reported are written.
            if ($state->spentTodayMinor !== null) {
                $account->current_daily_spend = max(0, $state->spentTodayMinor);
            }

            if ($state->spentThisMonthMinor !== null) {
                $account->current_monthly_spend = max(0, $state->spentThisMonthMinor);
            }

            if ($state->dailySpendLimitMinor !== null) {
                $account->daily_spend_limit = max(0, $state->dailySpendLimitMinor);
            }

            if ($state->monthlySpendLimitMinor !== null) {
                $account->monthly_spend_limit = max(0, $state->monthlySpendLimitMinor);
            }

            $account->billing_status = $billing;
            $account->consecutive_failures = 0;
            $account->last_error = null;

            // Stamped before the verdict is derived: the account has just been
            // looked at, and deriveHealth() reads this to decide whether the
            // figures are stale.
            $account->last_synced_at = CarbonImmutable::now();

            $account->health_status = $this->deriveHealth($account, $state, $billing);

            $account->save();
        });

        $this->settle($account, $previous, $before, $state->disapprovalReason);

        return $account->health_status;
    }

    /**
     * A failed check. The health verdict only moves once failures have piled
     * up past the configured thresholds; before that the account keeps the
     * health it had, and only the counter and the error message change.
     */
    public function recordFailure(AdAccount $account, ProviderUnavailable $exception): AdAccountHealth
    {
        $previous = $account->health_status;
        $before = AuditRecorder::snapshot($account);
        $failures = $account->consecutive_failures + 1;

        $health = $previous;

        if (! $exception->retryable) {
            // The provider gave a definite answer and it was a refusal.
            $health = AdAccountHealth::Critical;
        } elseif ($failures >= $this->threshold('failures_before_critical')) {
            $health = AdAccountHealth::Critical;
        } elseif ($failures >= $this->threshold('failures_before_degraded')) {
            $health = AdAccountHealth::AtRisk;
        }

        DB::transaction(function () use ($account, $failures, $exception, $health): void {
            $account->consecutive_failures = $failures;
            // Truncated, and the message is the adapter's own client-safe
            // text: provider error codes do not belong in stored state.
            $account->last_error = substr($exception->clientMessage, 0, 250);
            $account->health_status = $health;
            $account->save();
        });

        $this->settle($account, $previous, $before, $exception->clientMessage);

        return $health;
    }

    /**
     * Health derived from an account's own numbers, with no provider call.
     *
     * Used by the sweep to notice an account that has quietly gone stale, and
     * by the interface to explain a verdict without making a request.
     */
    public function deriveHealth(
        AdAccount $account,
        ?ProviderAccountState $state = null,
        ?AdAccountBillingStatus $billing = null,
    ): AdAccountHealth {
        $billing ??= $account->billing_status;

        if ($state?->disapprovalReason !== null) {
            return AdAccountHealth::Critical;
        }

        if ($this->providerReportsDisabled($state)) {
            return AdAccountHealth::Critical;
        }

        if (! $billing->permitsSpend()) {
            return AdAccountHealth::AtRisk;
        }

        $utilisation = $account->dailyUtilisationPercent();

        if ($utilisation !== null) {
            if ($utilisation >= $this->threshold('utilisation_critical_percent')) {
                return AdAccountHealth::AtRisk;
            }

            if ($utilisation >= $this->threshold('utilisation_warning_percent')) {
                return AdAccountHealth::Degraded;
            }
        }

        if ($this->isStale($account)) {
            // Not a failure, and deliberately not "healthy" either: an account
            // nobody has looked at for hours is an account we cannot vouch for.
            return AdAccountHealth::Unknown;
        }

        return AdAccountHealth::Healthy;
    }

    public function isStale(AdAccount $account): bool
    {
        $hours = $this->threshold('stale_after_hours');

        return $account->last_synced_at === null
            || $account->last_synced_at->lessThan(Carbon::now()->subHours($hours));
    }

    /**
     * Providers describe billing in their own words. Anything unrecognised
     * stays Unknown rather than being guessed at as healthy.
     */
    private function billingFrom(ProviderAccountState $state): AdAccountBillingStatus
    {
        if ($state->billingStatus === null) {
            return AdAccountBillingStatus::Unknown;
        }

        $reported = strtoupper(str_replace([' ', '-'], '_', trim($state->billingStatus)));

        return match (true) {
            in_array($reported, ['CURRENT', 'ACTIVE', 'OK', 'SETTLED'], true) => AdAccountBillingStatus::Current,
            str_contains($reported, 'NO_PAYMENT') || str_contains($reported, 'PAYMENT_METHOD') => AdAccountBillingStatus::PaymentMethodMissing,
            str_contains($reported, 'FAILED') || str_contains($reported, 'DECLIN') => AdAccountBillingStatus::PaymentFailed,
            str_contains($reported, 'LIMIT') => AdAccountBillingStatus::LimitReached,
            str_contains($reported, 'SUSPEND') || str_contains($reported, 'CLOSED') => AdAccountBillingStatus::Suspended,
            default => AdAccountBillingStatus::Unknown,
        };
    }

    private function providerReportsDisabled(?ProviderAccountState $state): bool
    {
        if ($state?->status === null) {
            return false;
        }

        $reported = strtoupper(trim($state->status));

        foreach (['DISABLED', 'CLOSED', 'RESTRICTED', 'UNSETTLED', 'PENDING_RISK_REVIEW'] as $bad) {
            if (str_contains($reported, $bad)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record the change and tell someone — once per transition, not on every
     * sweep while the problem persists.
     *
     * @param  array<string, mixed>  $before
     */
    private function settle(AdAccount $account, AdAccountHealth $previous, array $before, ?string $detail): void
    {
        if ($account->health_status === $previous) {
            return;
        }

        $this->audit->recordChange(
            action: AuditAction::AdAccountHealthChanged,
            resource: $account,
            before: $before,
            context: [
                'from' => $previous->value,
                'to' => $account->health_status->value,
                'detail' => $detail,
            ],
        );

        if (! $account->health_status->needsAttention()) {
            return;
        }

        foreach ($this->operatorsToNotify() as $operator) {
            $operator->notify(new AdAccountNeedsAttention($account, $account->health_status, $detail));
        }
    }

    /**
     * The people who can actually do something about it: platform staff
     * holding the health permission. Notifying anyone else would be telling a
     * client about infrastructure that is not theirs.
     *
     * @return iterable<User>
     */
    private function operatorsToNotify(): iterable
    {
        return User::query()
            ->whereNull('tenant_id')
            ->get()
            ->filter(fn (User $user): bool => $user->hasPermissionTo(Permission::AdAccountsManageHealth));
    }

    private function threshold(string $key): int
    {
        return (int) config("platform.advertising.health.{$key}");
    }
}
