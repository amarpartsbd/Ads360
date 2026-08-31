<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Models;

use App\Domains\Analytics\Enums\ReconciliationStatus;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Domains\Tenant\Models\Organization;
use App\Support\Concerns\HasPublicId;
use App\Support\Values\Currency;
use App\Support\Values\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One comparison of provider spend against the ledger (spec §78).
 *
 * @property ReconciliationStatus $status
 * @property int $provider_spend
 * @property int $ledger_spend
 * @property int $variance
 */
class SpendReconciliation extends Model
{
    use BelongsToTenant;
    use HasPublicId;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'campaign_id',
        'period_start',
        'period_end',
        'currency',
        'provider_spend',
        'ledger_spend',
        'variance',
        'status',
        'metadata',
        'checked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReconciliationStatus::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'provider_spend' => 'integer',
            'ledger_spend' => 'integer',
            'variance' => 'integer',
            'metadata' => 'array',
            'checked_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
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
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function currency(): Currency
    {
        return Currency::of($this->currency);
    }

    public function providerSpend(): Money
    {
        return Money::ofMinor($this->provider_spend, $this->currency());
    }

    public function ledgerSpend(): Money
    {
        return Money::ofMinor($this->ledger_spend, $this->currency());
    }

    public function variance(): Money
    {
        return Money::ofMinor($this->variance, $this->currency());
    }

    /**
     * Whether the provider says more was spent than the platform charged for.
     * That direction is the one that costs the platform money, and is the one
     * worth looking at first.
     */
    public function underCharged(): bool
    {
        return $this->variance > 0;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNeedingAttention(Builder $query): Builder
    {
        return $query->where('status', ReconciliationStatus::Investigating);
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'id' => $this->public_id,
            'period' => $this->period_start?->toDateString().' to '.$this->period_end?->toDateString(),
            'provider_spend' => $this->providerSpend()->toDecimal(),
            'ledger_spend' => $this->ledgerSpend()->toDecimal(),
            'variance' => $this->variance()->toDecimal(),
            'currency' => $this->currency,
            'status' => $this->status->value,
        ];
    }
}
