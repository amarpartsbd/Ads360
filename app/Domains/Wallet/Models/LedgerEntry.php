<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Wallet\Enums\LedgerEntryType;
use App\Support\Concerns\HasPublicId;
use App\Support\Values\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One movement in the financial record (spec §31).
 *
 * Immutable: the model refuses updates and deletes outright rather than relying
 * on nobody calling them. A mistake is corrected by writing a reversal that
 * points back at the entry it undoes, which leaves both the error and the
 * correction visible (spec §62).
 *
 * @property LedgerEntryType $type
 * @property int $debit
 * @property int $credit
 * @property int $reserved_delta
 */
class LedgerEntry extends Model
{
    use BelongsToTenant;
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'wallet_id',
        'transaction_group_id',
        'type',
        'reference_type',
        'reference_id',
        'debit',
        'credit',
        'reserved_delta',
        'currency',
        'balance_snapshot',
        'reserved_snapshot',
        'description',
        'reverses_entry_id',
        'rate_snapshot',
        'pricing_snapshot',
        'metadata',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'debit' => 'integer',
            'credit' => 'integer',
            'reserved_delta' => 'integer',
            'balance_snapshot' => 'integer',
            'reserved_snapshot' => 'integer',
            'rate_snapshot' => 'array',
            'pricing_snapshot' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException(
                'Ledger entries are immutable. Correct a mistake with a reversal entry.'
            );
        });

        static::deleting(function (): never {
            throw new RuntimeException(
                'Ledger entries are never deleted. Correct a mistake with a reversal entry.'
            );
        });
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    /** The signed effect on available balance, as money. */
    public function amount(): Money
    {
        return Money::ofMinor($this->credit - $this->debit, $this->currency);
    }

    /** The absolute size of the movement, for display. */
    public function magnitude(): Money
    {
        return Money::ofMinor(max($this->debit, $this->credit), $this->currency);
    }

    public function isCredit(): bool
    {
        return $this->credit > 0;
    }

    public function isReversal(): bool
    {
        return $this->reverses_entry_id !== null;
    }
}
