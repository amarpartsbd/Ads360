<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Tenant;
use App\Support\Concerns\HasPublicId;
use App\Support\Values\Money;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One effective-dated rate for a currency pair (spec §35).
 *
 * Not tenant-scoped by the global scope: platform rates have no tenant, and
 * resolution deliberately falls back from a tenant's own card to the platform's.
 * Access is decided by policy instead.
 *
 * @property string $market_rate
 * @property string $client_rate
 */
class ExchangeRate extends Model
{
    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory;

    use HasPublicId;

    protected $fillable = [
        'tenant_id',
        'base_currency',
        'quote_currency',
        'market_rate',
        'client_rate',
        'effective_from',
        'effective_until',
        'source',
        'note',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            // Rates stay strings: casting to float would put a float back into
            // money arithmetic, which is the one thing §60 forbids.
            'market_rate' => 'string',
            'client_rate' => 'string',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCurrent(): bool
    {
        return $this->effective_until === null && ! $this->effective_from->isFuture();
    }

    /**
     * Convert an amount at this rate.
     *
     * Rounding is half-up by default and stated explicitly, because the result
     * is money and the caller owns that policy (spec §60).
     */
    public function convert(Money $amount, string $rounding = Money::ROUND_HALF_UP): Money
    {
        return Money::ofMinor(
            $amount->multipliedBy($this->client_rate, $rounding)->minorUnits,
            $this->quote_currency,
        );
    }

    /**
     * The markup the platform takes on this pair, as a percentage string.
     * Presentational only: fees are calculated from the rates themselves.
     */
    public function markupPercentage(): string
    {
        $market = (float) $this->market_rate;

        if ($market <= 0.0) {
            return '0.00';
        }

        return number_format((((float) $this->client_rate - $market) / $market) * 100, 2, '.', '');
    }

    /**
     * The snapshot stored on any transaction that used this rate, so history is
     * self-describing and never needs to look the rate up again (spec §35).
     *
     * @return array<string, string|null>
     */
    public function snapshot(): array
    {
        return [
            'rate_id' => $this->public_id,
            'base_currency' => $this->base_currency,
            'quote_currency' => $this->quote_currency,
            'market_rate' => $this->market_rate,
            'client_rate' => $this->client_rate,
            'effective_from' => $this->effective_from->toIso8601String(),
        ];
    }

    protected static function newFactory(): ExchangeRateFactory
    {
        return ExchangeRateFactory::new();
    }
}
