<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Services;

use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Wallet\DTOs\LedgerMovement;
use App\Domains\Wallet\Enums\LedgerEntryType;
use App\Domains\Wallet\Enums\ReservationStatus;
use App\Domains\Wallet\Enums\WalletStatus;
use App\Domains\Wallet\Exceptions\InsufficientFunds;
use App\Domains\Wallet\Exceptions\InvalidLedgerOperation;
use App\Domains\Wallet\Models\LedgerEntry;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletReservation;
use App\Support\Values\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The wallet operations the rest of the platform calls (spec §31, §32).
 *
 * Every method here is a thin, named wrapper over LedgerWriter::post(), which
 * owns the locking. Nothing in this class mutates a balance directly.
 */
final class WalletService
{
    public function __construct(private readonly LedgerWriter $ledger) {}

    /**
     * The organization's wallet in a currency, created on first use.
     *
     * Concurrent first calls race, so the unique index is the arbiter: if the
     * insert loses, the winner's row is read back.
     */
    public function walletFor(Organization $organization, ?string $currency = null): Wallet
    {
        $currency ??= $organization->default_currency;

        $existing = Wallet::query()
            ->where('organization_id', $organization->getKey())
            ->where('currency', $currency)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $wallet = new Wallet([
                'organization_id' => $organization->getKey(),
                'currency' => $currency,
                'status' => WalletStatus::Active,
            ]);
            $wallet->tenant_id = $organization->tenant_id;
            $wallet->save();

            return $wallet;
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return Wallet::query()
                ->where('organization_id', $organization->getKey())
                ->where('currency', $currency)
                ->firstOrFail();
        }
    }

    /**
     * Money in — a verified deposit, in practice.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function deposit(
        Wallet $wallet,
        Money $amount,
        string $description,
        ?Model $reference = null,
        array $metadata = [],
        ?User $actor = null,
    ): LedgerEntry {
        $entries = $this->ledger->post($wallet, [
            LedgerMovement::credit(
                LedgerEntryType::Deposit,
                $amount,
                $description,
                $reference,
                $metadata,
            ),
        ], $actor);

        return $entries->first();
    }

    /**
     * Money out, straight from available balance.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws InsufficientFunds
     */
    public function debit(
        Wallet $wallet,
        Money $amount,
        LedgerEntryType $type,
        string $description,
        ?Model $reference = null,
        array $metadata = [],
        ?User $actor = null,
    ): LedgerEntry {
        $entries = $this->ledger->post($wallet, [
            LedgerMovement::debit($type, $amount, $description, $reference, $metadata),
        ], $actor);

        return $entries->first();
    }

    /**
     * Post several movements as one event — a spend with its fees and tax.
     *
     * @param  list<LedgerMovement>  $movements
     * @return Collection<int, LedgerEntry>
     */
    public function postGroup(Wallet $wallet, array $movements, ?User $actor = null): Collection
    {
        return $this->ledger->post($wallet, $movements, $actor);
    }

    /**
     * Hold budget against the wallet (spec §32).
     *
     * The funds move from available to reserved: the client still holds them,
     * but can no longer commit them elsewhere. The reservation row and its
     * ledger entry are written in one transaction, so a hold can never exist
     * without the movement that created it.
     *
     * @throws InsufficientFunds
     */
    public function reserve(
        Wallet $wallet,
        Money $amount,
        Model $reference,
        ?Carbon $expiresAt = null,
        ?User $actor = null,
    ): WalletReservation {
        return DB::transaction(function () use ($wallet, $amount, $reference, $expiresAt, $actor): WalletReservation {
            // The wallet row is locked *first*, before any write that
            // references it. Inserting the reservation takes a share lock on
            // this same row through its foreign key, so acquiring the exclusive
            // lock afterwards lets two concurrent reservations deadlock. Every
            // method in this class therefore locks wallet-then-reservation, in
            // that order, and never the other way round.
            $this->ledger->lock($wallet);

            $reservation = new WalletReservation([
                'organization_id' => $wallet->organization_id,
                'wallet_id' => $wallet->getKey(),
                'reference_type' => $reference::class,
                'reference_id' => (string) $reference->getKey(),
                'amount' => $amount->minorUnits,
                'currency' => $amount->currency->code,
                'status' => ReservationStatus::Held,
                'expires_at' => $expiresAt,
                'created_by' => $actor?->getKey(),
            ]);
            // Stamped from the wallet, not the request context: reservations
            // are created by the campaign approval pipeline, which runs queued.
            $reservation->tenant_id = $wallet->tenant_id;
            $reservation->save();

            $this->ledger->post($wallet, [
                new LedgerMovement(
                    type: LedgerEntryType::Reserve,
                    amount: $amount,
                    isCredit: false,
                    description: 'Budget reserved',
                    reference: $reservation,
                    reservedDelta: $amount->minorUnits,
                ),
            ], $actor);

            return $reservation;
        });
    }

    /**
     * Draw actual spend against a hold (spec §32).
     *
     * Written as two entries in one group: the held amount returns to available
     * and is then spent out of the wallet. That keeps every entry single-sided
     * and makes the statement read the way the money actually moved.
     *
     * @param  array<string, mixed>  $metadata
     * @return Collection<int, LedgerEntry>
     */
    public function capture(
        WalletReservation $reservation,
        Money $amount,
        string $description,
        array $metadata = [],
        ?User $actor = null,
    ): Collection {
        return DB::transaction(function () use ($reservation, $amount, $description, $metadata, $actor): Collection {
            // Wallet before reservation, matching the order used everywhere
            // else, so concurrent captures queue instead of deadlocking.
            $wallet = $reservation->wallet()->withoutGlobalScopes()->firstOrFail();
            $this->ledger->lock($wallet);

            /** @var WalletReservation $locked */
            $locked = WalletReservation::query()
                ->withoutGlobalScopes()
                ->whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDrawable($locked, $amount);

            $entries = $this->ledger->post($wallet, [
                new LedgerMovement(
                    type: LedgerEntryType::Release,
                    amount: $amount,
                    isCredit: true,
                    description: 'Reserved budget applied to spend',
                    reference: $locked,
                    reservedDelta: -$amount->minorUnits,
                ),
                LedgerMovement::debit(
                    LedgerEntryType::CampaignSpend,
                    $amount,
                    $description,
                    $locked,
                    $metadata,
                ),
            ], $actor);

            $captured = $locked->captured_amount + $amount->minorUnits;
            $exhausted = $captured + $locked->released_amount >= $locked->amount;

            $locked->forceFill([
                'captured_amount' => $captured,
                'status' => $exhausted ? ReservationStatus::Captured : ReservationStatus::PartiallyCaptured,
                'closed_at' => $exhausted ? Carbon::now() : null,
            ])->save();

            $reservation->setRawAttributes($locked->getAttributes(), true);

            return $entries;
        });
    }

    /**
     * Give back what a hold did not spend (spec §32).
     *
     * Called when a campaign completes or is cancelled. Releasing an already
     * closed reservation is a no-op rather than an error, so a retried job
     * cannot return the money twice.
     */
    public function release(
        WalletReservation $reservation,
        ?Money $amount = null,
        string $description = 'Unused budget released',
        ?User $actor = null,
    ): ?LedgerEntry {
        return DB::transaction(function () use ($reservation, $amount, $description, $actor): ?LedgerEntry {
            // Wallet before reservation, as above.
            $wallet = $reservation->wallet()->withoutGlobalScopes()->firstOrFail();
            $this->ledger->lock($wallet);

            /** @var WalletReservation $locked */
            $locked = WalletReservation::query()
                ->withoutGlobalScopes()
                ->whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isOpen()) {
                return null;
            }

            $remaining = $locked->remaining();
            $amount ??= $remaining;

            if ($amount->isZero()) {
                $this->closeAsReleased($locked, 0);
                $reservation->setRawAttributes($locked->getAttributes(), true);

                return null;
            }

            if ($amount->greaterThan($remaining)) {
                throw InvalidLedgerOperation::exceedsReservation($locked->public_id);
            }

            $entries = $this->ledger->post($wallet, [
                new LedgerMovement(
                    type: LedgerEntryType::Release,
                    amount: $amount,
                    isCredit: true,
                    description: $description,
                    reference: $locked,
                    reservedDelta: -$amount->minorUnits,
                ),
            ], $actor);

            $released = $locked->released_amount + $amount->minorUnits;
            $exhausted = $locked->captured_amount + $released >= $locked->amount;

            $locked->forceFill([
                'released_amount' => $released,
                'status' => $exhausted
                    ? ($locked->captured_amount > 0 ? ReservationStatus::Captured : ReservationStatus::Released)
                    : ReservationStatus::PartiallyCaptured,
                'closed_at' => $exhausted ? Carbon::now() : null,
            ])->save();

            $reservation->setRawAttributes($locked->getAttributes(), true);

            return $entries->first();
        });
    }

    /**
     * Undo an entry with an opposing one (spec §31, §62).
     */
    public function reverse(LedgerEntry $entry, string $reason, ?User $actor = null): LedgerEntry
    {
        return $this->ledger->reverse($entry, $reason, $actor);
    }

    private function assertDrawable(WalletReservation $reservation, Money $amount): void
    {
        if (! $reservation->isOpen()) {
            throw InvalidLedgerOperation::reservationClosed($reservation->public_id);
        }

        if (! $amount->isPositive()) {
            throw InvalidLedgerOperation::nonPositiveAmount('capture');
        }

        if ($amount->greaterThan($reservation->remaining())) {
            throw InvalidLedgerOperation::exceedsReservation($reservation->public_id);
        }
    }

    private function closeAsReleased(WalletReservation $reservation, int $released): void
    {
        $reservation->forceFill([
            'status' => $reservation->captured_amount > 0
                ? ReservationStatus::Captured
                : ReservationStatus::Released,
            'closed_at' => Carbon::now(),
        ])->save();
    }
}
