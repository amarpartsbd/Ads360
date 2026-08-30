<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Domains\Wallet\Enums\ReservationStatus;
use App\Support\Concerns\HasPublicId;
use App\Support\Values\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A budget hold against a wallet (spec §32).
 *
 * @property int $amount
 * @property int $captured_amount
 * @property int $released_amount
 * @property ReservationStatus $status
 */
class WalletReservation extends Model
{
    use BelongsToTenant;
    use HasPublicId;

    protected $fillable = [
        'organization_id',
        'wallet_id',
        'reference_type',
        'reference_id',
        'amount',
        'currency',
        'status',
        'expires_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'amount' => 'integer',
            'captured_amount' => 'integer',
            'released_amount' => 'integer',
            'expires_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function amountMoney(): Money
    {
        return Money::ofMinor($this->amount, $this->currency);
    }

    public function capturedMoney(): Money
    {
        return Money::ofMinor($this->captured_amount, $this->currency);
    }

    /** What is still held and available to draw against. */
    public function remaining(): Money
    {
        return Money::ofMinor(
            $this->amount - $this->captured_amount - $this->released_amount,
            $this->currency,
        );
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
