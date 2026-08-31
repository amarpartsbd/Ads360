<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Models;

use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Wallet\Enums\WalletStatus;
use App\Support\Concerns\HasPublicId;
use App\Support\Values\Currency;
use App\Support\Values\Money;
use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * An organization's balance in one currency (spec §31).
 *
 * The cached columns are a convenience for reading. Nothing may set them
 * except the ledger writer, in the same transaction as the entries they
 * summarise — which is why there is no `fill()`-able balance here and why
 * `recomputeFromLedger()` exists to prove the cache still agrees.
 *
 * @property int $available_balance_cached
 * @property int $reserved_balance_cached
 * @property WalletStatus $status
 */
class Wallet extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<WalletFactory> */
    use HasFactory;

    use HasPublicId;

    /**
     * Balances are deliberately absent: they are maintained by the ledger
     * writer, never mass assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'currency',
        'status',
        'status_reason',
    ];

    /**
     * Mirrors the database defaults so a freshly created wallet reports a zero
     * balance rather than a null one. Eloquent does not read column defaults
     * back after an insert, and without this every client would hit an error on
     * their wallet page before their first deposit.
     *
     * @var array<string, int>
     */
    protected $attributes = [
        'available_balance_cached' => 0,
        'reserved_balance_cached' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WalletStatus::class,
            'available_balance_cached' => 'integer',
            'reserved_balance_cached' => 'integer',
            'last_reconciled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * @return HasMany<WalletReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(WalletReservation::class);
    }

    public function currency(): Currency
    {
        return Currency::of($this->currency);
    }

    /** What the client may spend right now. */
    public function availableBalance(): Money
    {
        return Money::ofMinor($this->available_balance_cached, $this->currency());
    }

    /** Held against approved campaigns and not spendable elsewhere. */
    public function reservedBalance(): Money
    {
        return Money::ofMinor($this->reserved_balance_cached, $this->currency());
    }

    /** Everything the client holds, spendable or not. */
    public function totalBalance(): Money
    {
        return $this->availableBalance()->plus($this->reservedBalance());
    }

    public function hasAvailable(Money $amount): bool
    {
        return $this->availableBalance()->greaterThanOrEqual($amount);
    }

    /**
     * Recompute both balances by replaying the ledger.
     *
     * This is what makes the cached columns safe to rely on: reconciliation
     * (spec §78) compares this against the stored values, and any drift is a
     * defect to investigate rather than something to quietly overwrite.
     *
     * @return array{available: int, reserved: int}
     */
    public function recomputeFromLedger(): array
    {
        /** @var object{available: int|string|null, reserved: int|string|null}|null $totals */
        $totals = DB::table('ledger_entries')
            ->where('wallet_id', $this->getKey())
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS available')
            ->selectRaw('COALESCE(SUM(reserved_delta), 0) AS reserved')
            ->first();

        return [
            'available' => (int) ($totals->available ?? 0),
            'reserved' => (int) ($totals->reserved ?? 0),
        ];
    }

    /** Whether the cached balances still agree with the ledger. */
    public function isReconciled(): bool
    {
        $computed = $this->recomputeFromLedger();

        return $computed['available'] === $this->available_balance_cached
            && $computed['reserved'] === $this->reserved_balance_cached;
    }

    protected static function newFactory(): WalletFactory
    {
        return WalletFactory::new();
    }
}
