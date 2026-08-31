<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Models;

use App\Domains\Advertising\Enums\AdAccountBillingStatus;
use App\Domains\Advertising\Enums\AdAccountHealth;
use App\Domains\Advertising\Enums\AdAccountStatus;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Integration\Models\ProviderConnection;
use App\Support\Concerns\HasPublicId;
use App\Support\Values\Currency;
use App\Support\Values\Money;
use Database\Factories\AdAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One advertising account the platform operates on clients' behalf (spec §17).
 *
 * Not tenant-scoped, and that is on purpose: the inventory is shared, an
 * account may serve different clients over its life, and no client is ever
 * shown this table. Access is decided by AdAccountPolicy, which admits
 * platform staff only.
 *
 * Spend figures are integer minor units in the account's own currency
 * (spec §59). They are a mirror of what the provider reports, refreshed by
 * sync; nothing here is authoritative for billing the client — the ledger is.
 *
 * @property Provider $provider
 * @property AdAccountStatus $status
 * @property AdAccountHealth $health_status
 * @property AdAccountBillingStatus $billing_status
 * @property int $current_daily_spend
 * @property int $current_monthly_spend
 * @property int $committed_amount
 * @property int $risk_score
 * @property int $allocation_priority
 */
class AdAccount extends Model
{
    /** @use HasFactory<AdAccountFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    /**
     * Spend counters are absent deliberately: they are written by the sync
     * path from provider figures, never mass assigned from a form.
     *
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'external_account_id',
        'name',
        'currency',
        'timezone',
        'status',
        'health_status',
        'billing_status',
        'daily_spend_limit',
        'monthly_spend_limit',
        'risk_score',
        'allocation_priority',
        'provider_connection_id',
        'metadata',
        'created_by',
    ];

    /**
     * Mirrors the column defaults so a model built in memory reports zeroes
     * rather than nulls before it is reloaded.
     *
     * @var array<string, int>
     */
    protected $attributes = [
        'current_daily_spend' => 0,
        'current_monthly_spend' => 0,
        'committed_amount' => 0,
        'risk_score' => 0,
        'allocation_priority' => 50,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'status' => AdAccountStatus::class,
            'health_status' => AdAccountHealth::class,
            'billing_status' => AdAccountBillingStatus::class,
            'daily_spend_limit' => 'integer',
            'monthly_spend_limit' => 'integer',
            'current_daily_spend' => 'integer',
            'current_monthly_spend' => 'integer',
            'committed_amount' => 'integer',
            'risk_score' => 'integer',
            'allocation_priority' => 'integer',
            'metadata' => 'array',
            'last_synced_at' => 'immutable_datetime',
            'last_allocated_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ProviderConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class, 'provider_connection_id');
    }

    /**
     * @return BelongsToMany<AdAccountPool, $this>
     */
    public function pools(): BelongsToMany
    {
        return $this->belongsToMany(AdAccountPool::class, 'ad_account_pool_members')
            ->withPivot(['weight', 'added_by'])
            ->withTimestamps();
    }

    public function currency(): Currency
    {
        return Currency::of($this->currency);
    }

    public function dailySpendLimit(): ?Money
    {
        return $this->daily_spend_limit === null
            ? null
            : Money::ofMinor($this->daily_spend_limit, $this->currency());
    }

    public function monthlySpendLimit(): ?Money
    {
        return $this->monthly_spend_limit === null
            ? null
            : Money::ofMinor($this->monthly_spend_limit, $this->currency());
    }

    public function currentDailySpend(): Money
    {
        return Money::ofMinor($this->current_daily_spend, $this->currency());
    }

    public function currentMonthlySpend(): Money
    {
        return Money::ofMinor($this->current_monthly_spend, $this->currency());
    }

    public function committedAmount(): Money
    {
        return Money::ofMinor($this->committed_amount, $this->currency());
    }

    /**
     * What is left of today's limit once spend and outstanding commitments are
     * taken off it. Null when no daily limit is configured, which means "not
     * constrained here" rather than "unlimited headroom" — the caller decides
     * what to do with that.
     */
    public function dailyHeadroom(): ?Money
    {
        if ($this->daily_spend_limit === null) {
            return null;
        }

        $remaining = $this->daily_spend_limit - $this->current_daily_spend - $this->committed_amount;

        return Money::ofMinor(max(0, $remaining), $this->currency());
    }

    public function monthlyHeadroom(): ?Money
    {
        if ($this->monthly_spend_limit === null) {
            return null;
        }

        $remaining = $this->monthly_spend_limit - $this->current_monthly_spend;

        return Money::ofMinor(max(0, $remaining), $this->currency());
    }

    /**
     * How much of the daily limit is used, as whole percent. Null when there
     * is no limit to measure against.
     */
    public function dailyUtilisationPercent(): ?int
    {
        if ($this->daily_spend_limit === null || $this->daily_spend_limit === 0) {
            return null;
        }

        $used = $this->current_daily_spend + $this->committed_amount;

        return (int) min(100, intdiv($used * 100, $this->daily_spend_limit));
    }

    /**
     * Whether the account is in a state where allocation may consider it at
     * all. Pool rules narrow further; this is the floor below which no pool
     * setting can rescue an account.
     */
    public function isAllocatable(): bool
    {
        return $this->status->isAllocatable()
            && $this->health_status->isAllocatable()
            && $this->billing_status->permitsSpend()
            && $this->deleted_at === null;
    }

    public function needsAttention(): bool
    {
        return $this->health_status->needsAttention()
            || $this->billing_status->needsAttention()
            || $this->status === AdAccountStatus::Suspended;
    }

    /**
     * Accounts allocation may draw from, filtered in the database so a caller
     * never pages through inventory it cannot use.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAllocatable(Builder $query): Builder
    {
        return $query
            ->where('status', AdAccountStatus::Active)
            ->whereIn('health_status', [
                AdAccountHealth::Healthy,
                AdAccountHealth::Unknown,
                AdAccountHealth::Degraded,
            ])
            ->whereIn('billing_status', [
                AdAccountBillingStatus::Current,
                AdAccountBillingStatus::Unknown,
            ]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForProvider(Builder $query, Provider $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNeedingAttention(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->whereIn('health_status', [AdAccountHealth::AtRisk, AdAccountHealth::Critical])
            ->orWhereNotIn('billing_status', [
                AdAccountBillingStatus::Current,
                AdAccountBillingStatus::Unknown,
            ])
            ->orWhere('status', AdAccountStatus::Suspended));
    }

    /**
     * Safe to write to a log or an audit entry: identifies the account without
     * carrying anything the provider would consider a credential (spec §12).
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'public_id' => $this->public_id,
            'provider' => $this->provider->value,
            'external_account_id' => $this->external_account_id,
            'name' => $this->name,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'health_status' => $this->health_status->value,
            'billing_status' => $this->billing_status->value,
        ];
    }

    protected static function newFactory(): AdAccountFactory
    {
        return AdAccountFactory::new();
    }
}
