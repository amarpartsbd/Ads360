<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Services;

use App\Domains\Identity\Models\User;
use App\Domains\Wallet\DTOs\LedgerMovement;
use App\Domains\Wallet\Exceptions\InsufficientFunds;
use App\Domains\Wallet\Exceptions\InvalidLedgerOperation;
use App\Domains\Wallet\Exceptions\WalletUnavailable;
use App\Domains\Wallet\Models\LedgerEntry;
use App\Domains\Wallet\Models\Wallet;
use App\Support\Exceptions\CurrencyMismatch;
use App\Support\Values\Money;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only thing in the application that writes the ledger or changes a wallet
 * balance (spec §31, §56).
 *
 * Everything goes through `post()`, which:
 *
 *   1. opens a transaction,
 *   2. takes a row lock on the wallet with SELECT ... FOR UPDATE,
 *   3. re-reads the balances *after* the lock is held,
 *   4. validates the whole batch against those balances,
 *   5. writes the entries and the new cached balances together,
 *   6. commits.
 *
 * Step 3 is the one that matters. A balance read before the lock is a balance
 * that another request may already have spent; re-reading under the lock is
 * what makes two concurrent debits serialise instead of both succeeding.
 */
final class LedgerWriter
{
    public function __construct(private readonly Guard $guard) {}

    /**
     * Post a group of movements as one business event.
     *
     * The whole batch succeeds or none of it does, so a spend can never be
     * recorded without the fee that accompanies it.
     *
     * @param  list<LedgerMovement>  $movements
     * @return Collection<int, LedgerEntry>
     *
     * @throws InsufficientFunds
     * @throws WalletUnavailable
     * @throws CurrencyMismatch
     */
    public function post(Wallet $wallet, array $movements, ?User $actor = null): Collection
    {
        if ($movements === []) {
            throw new InvalidLedgerOperation('A ledger post must contain at least one movement.');
        }

        $actor ??= $this->currentUser();
        $group = (string) Str::ulid();

        return DB::transaction(function () use ($wallet, $movements, $actor, $group): Collection {
            $locked = $this->lock($wallet);

            $available = $locked->available_balance_cached;
            $reserved = $locked->reserved_balance_cached;

            $entries = new Collection;

            foreach ($movements as $movement) {
                $this->assertMovementIsSound($locked, $movement);

                $minor = $movement->amount->minorUnits;

                $available += $movement->isCredit ? $minor : -$minor;
                $reserved += $movement->reservedDelta;

                // Checked per movement rather than only at the end: a batch
                // that dips negative in the middle is not a batch the client
                // could actually have afforded.
                if ($available < 0) {
                    throw InsufficientFunds::forDebit(
                        $movement->amount,
                        Money::ofMinor(
                            $available - ($movement->isCredit ? $minor : -$minor),
                            $locked->currency,
                        ),
                    );
                }

                if ($reserved < 0) {
                    throw new InvalidLedgerOperation(
                        'This would release more than the wallet currently has reserved.'
                    );
                }

                $entries->push($this->write(
                    $locked,
                    $movement,
                    $group,
                    $available,
                    $reserved,
                    $actor,
                ));
            }

            // Written once, from the running totals, in the same transaction as
            // the entries. The cache can therefore never disagree with the
            // ledger unless something wrote outside this method.
            $locked->forceFill([
                'available_balance_cached' => $available,
                'reserved_balance_cached' => $reserved,
            ])->save();

            // Keep the caller's instance in step so it does not report a stale
            // balance after a successful post.
            $wallet->setRawAttributes($locked->getAttributes(), true);

            return $entries;
        });
    }

    /**
     * Take the wallet row lock.
     *
     * Deliberately re-fetched rather than trusting the passed model: the caller
     * may have loaded it seconds ago, and this is the read that must be current.
     */
    public function lock(Wallet $wallet): Wallet
    {
        /** @var Wallet $locked */
        $locked = Wallet::query()
            ->withoutGlobalScopes()
            ->whereKey($wallet->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return $locked;
    }

    /**
     * Write a reversal of an existing entry.
     *
     * The reversal is a new entry on the opposite side pointing back at the
     * original, so both remain visible. A unique index makes a second reversal
     * of the same entry impossible even under concurrency (spec §62).
     */
    public function reverse(LedgerEntry $entry, string $reason, ?User $actor = null): LedgerEntry
    {
        if (! $entry->type->isReversible()) {
            throw InvalidLedgerOperation::notReversible($entry->type->value);
        }

        if (LedgerEntry::query()->where('reverses_entry_id', $entry->getKey())->exists()) {
            throw InvalidLedgerOperation::alreadyReversed($entry->public_id);
        }

        $wallet = $entry->wallet()->withoutGlobalScopes()->firstOrFail();
        $actor ??= $this->currentUser();
        $group = (string) Str::ulid();

        return DB::transaction(function () use ($entry, $wallet, $reason, $actor, $group): LedgerEntry {
            $locked = $this->lock($wallet);

            // Re-checked inside the lock: two reversals racing would both pass
            // the check above and only one may win.
            if (LedgerEntry::query()->where('reverses_entry_id', $entry->getKey())->exists()) {
                throw InvalidLedgerOperation::alreadyReversed($entry->public_id);
            }

            $available = $locked->available_balance_cached + $entry->debit - $entry->credit;

            if ($available < 0) {
                throw InsufficientFunds::forDebit(
                    $entry->magnitude(),
                    $locked->availableBalance(),
                );
            }

            $reversal = new LedgerEntry([
                'organization_id' => $entry->organization_id,
                'wallet_id' => $locked->getKey(),
                'transaction_group_id' => $group,
                'type' => \App\Domains\Wallet\Enums\LedgerEntryType::Reversal,
                'reference_type' => $entry->reference_type,
                'reference_id' => $entry->reference_id,
                // Mirrored: what was debited is credited back, and vice versa.
                'debit' => $entry->credit,
                'credit' => $entry->debit,
                'reserved_delta' => 0,
                'currency' => $entry->currency,
                'balance_snapshot' => $available,
                'reserved_snapshot' => $locked->reserved_balance_cached,
                'description' => $reason,
                'reverses_entry_id' => $entry->getKey(),
                'metadata' => ['reversed_entry' => $entry->public_id],
                'created_by' => $actor?->getKey(),
            ]);

            $reversal->tenant_id = $locked->tenant_id;
            $reversal->save();

            $locked->forceFill(['available_balance_cached' => $available])->save();
            $wallet->setRawAttributes($locked->getAttributes(), true);

            return $reversal;
        });
    }

    private function write(
        Wallet $wallet,
        LedgerMovement $movement,
        string $group,
        int $availableAfter,
        int $reservedAfter,
        ?User $actor,
    ): LedgerEntry {
        $minor = $movement->amount->minorUnits;

        $entry = new LedgerEntry([
            'organization_id' => $wallet->organization_id,
            'wallet_id' => $wallet->getKey(),
            'transaction_group_id' => $group,
            'type' => $movement->type,
            'reference_type' => $movement->reference !== null ? $movement->reference::class : null,
            'reference_id' => $movement->reference?->getKey() !== null
                ? (string) $movement->reference->getKey()
                : null,
            'debit' => $movement->isCredit ? 0 : $minor,
            'credit' => $movement->isCredit ? $minor : 0,
            'reserved_delta' => $movement->reservedDelta,
            'currency' => $wallet->currency,
            'balance_snapshot' => $availableAfter,
            'reserved_snapshot' => $reservedAfter,
            'description' => $movement->description,
            'reverses_entry_id' => $movement->reversesEntryId,
            'rate_snapshot' => $movement->rateSnapshot,
            'pricing_snapshot' => $movement->pricingSnapshot,
            'metadata' => $movement->metadata,
            'created_by' => $actor?->getKey(),
        ]);

        // Taken from the wallet rather than from the bound request context:
        // the ledger is written by queue workers and scheduled jobs too, where
        // there is no request and therefore no context to inherit.
        $entry->tenant_id = $wallet->tenant_id;
        $entry->save();

        return $entry;
    }

    private function assertMovementIsSound(Wallet $wallet, LedgerMovement $movement): void
    {
        if (! $movement->amount->isPositive()) {
            throw InvalidLedgerOperation::nonPositiveAmount($movement->type->value);
        }

        // Currencies are never converted implicitly: a mismatch means the
        // caller skipped the exchange-rate engine (spec §35).
        if ($movement->amount->currency->code !== $wallet->currency) {
            throw CurrencyMismatch::between($movement->amount->currency, $wallet->currency());
        }

        if ($movement->isCredit && ! $wallet->status->allowsCredit()) {
            throw WalletUnavailable::cannotCredit($wallet->status);
        }

        if (! $movement->isCredit && ! $wallet->status->allowsDebit()) {
            throw WalletUnavailable::cannotDebit($wallet->status);
        }
    }

    private function currentUser(): ?User
    {
        $user = $this->guard->user();

        return $user instanceof User ? $user : null;
    }
}
