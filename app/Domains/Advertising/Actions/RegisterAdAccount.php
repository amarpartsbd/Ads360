<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Actions;

use App\Domains\Advertising\Enums\AdAccountBillingStatus;
use App\Domains\Advertising\Enums\AdAccountHealth;
use App\Domains\Advertising\Enums\AdAccountStatus;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Exceptions\AdAccountException;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use App\Support\Values\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Adds a provider account to the managed inventory (spec §17).
 *
 * An account arrives in PendingSetup, never Active: registering it records
 * that it exists, and someone still has to confirm billing and limits are in
 * place before allocation may hand it to a client.
 */
final class RegisterAdAccount
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        Provider $provider,
        string $externalAccountId,
        string $name,
        string $currency,
        string $timezone,
        User $actor,
        ?int $dailySpendLimitMinor = null,
        ?int $monthlySpendLimitMinor = null,
        ?int $providerConnectionId = null,
        array $metadata = [],
    ): AdAccount {
        // Rejects an unsupported currency before anything is written, rather
        // than storing a code no Money instance could later be built from.
        $currency = Currency::of($currency)->code;

        $this->assertLimitsSane($dailySpendLimitMinor, $monthlySpendLimitMinor);

        try {
            $account = DB::transaction(fn (): AdAccount => AdAccount::query()->create([
                'provider' => $provider,
                'external_account_id' => trim($externalAccountId),
                'name' => trim($name),
                'currency' => $currency,
                'timezone' => $timezone,
                'status' => AdAccountStatus::PendingSetup,
                'health_status' => AdAccountHealth::Unknown,
                'billing_status' => AdAccountBillingStatus::Unknown,
                'daily_spend_limit' => $dailySpendLimitMinor,
                'monthly_spend_limit' => $monthlySpendLimitMinor,
                'provider_connection_id' => $providerConnectionId,
                'metadata' => $metadata,
                'created_by' => $actor->getKey(),
            ]));
        } catch (QueryException $exception) {
            // The unique index is the authority on duplicates, not a prior
            // read: two operators registering the same account at once would
            // both pass a check-then-insert.
            if ($this->isUniqueViolation($exception)) {
                throw AdAccountException::duplicateExternalAccount();
            }

            throw $exception;
        }

        $this->audit->record(
            action: AuditAction::AdAccountCreated,
            resource: $account,
            after: $account->describe(),
            actor: $actor,
        );

        return $account;
    }

    private function assertLimitsSane(?int $daily, ?int $monthly): void
    {
        foreach ([$daily, $monthly] as $limit) {
            if ($limit !== null && $limit < 0) {
                throw AdAccountException::limitBelowCommitment();
            }
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23505';
    }
}
