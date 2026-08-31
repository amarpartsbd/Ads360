<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Models;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Models\AdAccountPool;
use App\Domains\Campaign\Enums\BudgetType;
use App\Domains\Campaign\Enums\CampaignObjective;
use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Wallet\Models\WalletReservation;
use App\Support\Concerns\HasPublicId;
use App\Support\Values\Currency;
use App\Support\Values\Money;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One campaign (spec §21).
 *
 * The money columns are absent from `$fillable` on purpose. `budget_amount` is
 * the one figure the client chooses, and even that is converted from a decimal
 * string server-side; `fee_total`, `charged_total` and `captured_amount` are
 * written by the pricing engine and the ledger, never by a request (Rule 8).
 *
 * @property CampaignStatus $status
 * @property Provider $provider
 * @property CampaignObjective $objective
 * @property BudgetType $budget_type
 * @property int $budget_amount
 * @property int $fee_total
 * @property int $charged_total
 * @property int $captured_amount
 * @property int $reported_spend
 */
class Campaign extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'name',
        'provider',
        'objective',
        'status',
        'currency',
        'budget_type',
        'starts_at',
        'ends_at',
        'metadata',
        'created_by',
    ];

    /** @var array<string, int> */
    protected $attributes = [
        'fee_total' => 0,
        'charged_total' => 0,
        'captured_amount' => 0,
        'reported_spend' => 0,
        'account_commitment' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'objective' => CampaignObjective::class,
            'status' => CampaignStatus::class,
            'budget_type' => BudgetType::class,
            'budget_amount' => 'integer',
            'fee_total' => 'integer',
            'charged_total' => 'integer',
            'captured_amount' => 'integer',
            'reported_spend' => 'integer',
            'account_commitment' => 'integer',
            'pricing_snapshot' => 'array',
            'metadata' => 'array',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
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
     * @return HasMany<AdSet, $this>
     */
    public function adSets(): HasMany
    {
        return $this->hasMany(AdSet::class);
    }

    /**
     * @return HasMany<Ad, $this>
     */
    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
    }

    /**
     * @return HasMany<CampaignPublication, $this>
     */
    public function publications(): HasMany
    {
        return $this->hasMany(CampaignPublication::class);
    }

    /**
     * @return BelongsTo<AdAccount, $this>
     */
    public function adAccount(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class);
    }

    /**
     * @return BelongsTo<AdAccountPool, $this>
     */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(AdAccountPool::class, 'ad_account_pool_id');
    }

    /**
     * @return BelongsTo<WalletReservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(WalletReservation::class, 'wallet_reservation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function currency(): Currency
    {
        return Currency::of($this->currency);
    }

    /** What the client asked to spend on advertising. */
    public function budget(): Money
    {
        return Money::ofMinor($this->budget_amount, $this->currency());
    }

    /** The platform's fees on top of the budget. */
    public function feeTotal(): Money
    {
        return Money::ofMinor($this->fee_total, $this->currency());
    }

    /** Budget plus fees — what is held against the wallet. */
    public function chargedTotal(): Money
    {
        return Money::ofMinor($this->charged_total, $this->currency());
    }

    public function capturedAmount(): Money
    {
        return Money::ofMinor($this->captured_amount, $this->currency());
    }

    public function reportedSpend(): Money
    {
        return Money::ofMinor($this->reported_spend, $this->currency());
    }

    /** What is left of the hold. */
    public function remainingBudget(): Money
    {
        return Money::ofMinor(max(0, $this->charged_total - $this->captured_amount), $this->currency());
    }

    /**
     * How many days the campaign runs, for turning a daily budget into the
     * total that has to be held. A campaign with no end date counts as one
     * day: the wallet cannot hold an open-ended amount, which is why a daily
     * budget requires an end date before it can be submitted.
     */
    public function scheduledDays(): int
    {
        if ($this->starts_at === null || $this->ends_at === null) {
            return 1;
        }

        return max(1, (int) $this->starts_at->diffInDays($this->ends_at));
    }

    /**
     * The advertising spend the whole run commits to, before fees. A lifetime
     * budget is its own figure; a daily budget multiplies out.
     */
    public function committedBudget(): Money
    {
        return $this->budget_type === BudgetType::Lifetime
            ? $this->budget()
            : $this->budget()->multipliedBy($this->scheduledDays());
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function isPublished(): bool
    {
        return $this->provider_campaign_id !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('status', CampaignStatus::PendingReview);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [CampaignStatus::Active, CampaignStatus::Paused]);
    }

    /**
     * Safe for a log or an audit payload: identifies the campaign and its
     * money without carrying anything a provider would call a credential.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'provider' => $this->provider->value,
            'objective' => $this->objective->value,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'budget_type' => $this->budget_type->value,
            'budget_amount' => $this->budget_amount,
            'charged_total' => $this->charged_total,
        ];
    }

    protected static function newFactory(): CampaignFactory
    {
        return CampaignFactory::new();
    }
}
