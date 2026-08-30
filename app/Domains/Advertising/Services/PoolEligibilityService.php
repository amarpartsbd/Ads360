<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Services;

use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Models\AdAccountPool;
use App\Domains\Advertising\Values\AllocationRules;
use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Wallet\Models\Wallet;

/**
 * Answers whether a pool would give a particular client a particular account,
 * and if not, why (spec §18, §19).
 *
 * Allocation itself — picking one account and holding it — belongs with the
 * campaign engine. What lives here is the part that has to be right before any
 * picking happens: reading a pool's rules and applying them honestly. Keeping
 * it separate means the rules can be exercised in tests and explained in the
 * admin interface without an allocation having to happen.
 *
 * Every method returns reasons rather than a bare boolean. An operator looking
 * at an empty pool needs to know which rule emptied it.
 */
final class PoolEligibilityService
{
    /**
     * Whether the client itself clears the pool's bar, independent of any
     * account. Checked once per pool rather than once per account.
     *
     * @return list<string> Empty when the client qualifies; otherwise the reasons it does not.
     */
    public function clientFailures(AdAccountPool $pool, Organization $organization): array
    {
        $rules = $pool->rules();
        $failures = [];

        if (! $pool->isAllocatable()) {
            $failures[] = 'This pool is not active.';
        }

        $profile = $this->profileFor($organization);

        if ($profile?->status !== $rules->requiredVerificationStatus) {
            $failures[] = sprintf(
                'This pool requires clients to be %s.',
                strtolower($rules->requiredVerificationStatus->label()),
            );
        }

        if (! $rules->permitsCountry($organization->country)) {
            $failures[] = 'This pool does not serve the client\'s country.';
        }

        if (! $rules->permitsCategory($profile?->advertising_category)) {
            $failures[] = 'This pool does not accept the client\'s advertising category.';
        }

        if ($rules->minimumWalletBalanceMinor !== null
            && $this->availableBalanceMinor($organization, $pool->currency) < $rules->minimumWalletBalanceMinor) {
            $failures[] = 'The client\'s balance is below this pool\'s minimum.';
        }

        return $failures;
    }

    /**
     * Whether a specific account in the pool could take this client's work.
     *
     * @return list<string> Empty when the account qualifies.
     */
    public function accountFailures(AdAccountPool $pool, AdAccount $account, ?int $requiredMinor = null): array
    {
        $rules = $pool->rules();
        $failures = [];

        if (! $pool->accepts($account)) {
            $failures[] = 'This account does not match the pool\'s provider or currency.';

            // Nothing below is meaningful once the shapes disagree.
            return $failures;
        }

        if (! $account->isAllocatable()) {
            $failures[] = 'This account is not available for allocation.';
        }

        if ($rules->requireHealthyAccount && $account->health_status->needsAttention()) {
            $failures[] = 'This account is not healthy enough for this pool.';
        }

        if ($rules->maxAccountRiskScore !== null && $account->risk_score > $rules->maxAccountRiskScore) {
            $failures[] = 'This account carries more risk than the pool allows.';
        }

        $utilisation = $account->dailyUtilisationPercent();

        if ($rules->maxDailyUtilisationPercent !== null
            && $utilisation !== null
            && $utilisation > $rules->maxDailyUtilisationPercent) {
            $failures[] = 'This account is already too close to its daily limit for this pool.';
        }

        if ($requiredMinor !== null) {
            $failures = [...$failures, ...$this->headroomFailures($account, $rules, $requiredMinor)];
        }

        return $failures;
    }

    /**
     * The accounts in a pool that could serve this client right now.
     *
     * Loads the pool's members once and filters in memory: a pool holds tens
     * of accounts, not thousands, and the rules involve derived values that
     * the database cannot express as cheaply as it can compare columns.
     *
     * @return list<AdAccount>
     */
    public function eligibleAccounts(AdAccountPool $pool, Organization $organization, ?int $requiredMinor = null): array
    {
        if ($this->clientFailures($pool, $organization) !== []) {
            return [];
        }

        $accounts = $pool->accounts()->get();

        return array_values(array_filter(
            $accounts->all(),
            fn (AdAccount $account): bool => $this->accountFailures($pool, $account, $requiredMinor) === [],
        ));
    }

    /**
     * Headroom is checked against limits *and* the reserve the pool asks to be
     * left untouched, so a pool can hold back capacity for work it has not
     * been asked about yet.
     *
     * @return list<string>
     */
    private function headroomFailures(AdAccount $account, AllocationRules $rules, int $requiredMinor): array
    {
        $failures = [];
        $daily = $account->dailyHeadroom();

        if ($daily !== null && $daily->minorUnits - $rules->reserveHeadroomMinor < $requiredMinor) {
            $failures[] = 'This account does not have enough daily headroom left.';
        }

        $monthly = $account->monthlyHeadroom();

        if ($monthly !== null && $monthly->minorUnits < $requiredMinor) {
            $failures[] = 'This account does not have enough monthly headroom left.';
        }

        return $failures;
    }

    /**
     * Read without the tenant scope and filtered by organization explicitly:
     * allocation runs from a queue worker with no tenant bound, and the scope
     * would silently return nothing there. The explicit organization filter is
     * what keeps the read correct, not the ambient context (spec §7).
     */
    private function profileFor(Organization $organization): ?VerificationProfile
    {
        return VerificationProfile::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->first();
    }

    private function availableBalanceMinor(Organization $organization, string $currency): int
    {
        $wallet = Wallet::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('currency', strtoupper($currency))
            ->first();

        return $wallet?->available_balance_cached ?? 0;
    }
}
