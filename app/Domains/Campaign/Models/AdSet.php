<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Models;

use App\Domains\Campaign\Enums\AdSetStatus;
use App\Domains\Campaign\Enums\BidStrategy;
use App\Domains\Campaign\Values\Targeting;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use App\Support\Values\Currency;
use App\Support\Values\Money;
use Database\Factories\AdSetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The targeting and bidding layer of a campaign (spec §21).
 *
 * @property AdSetStatus $status
 * @property BidStrategy $bid_strategy
 */
class AdSet extends Model
{
    /** @use HasFactory<AdSetFactory> */
    use BelongsToTenant;

    use HasFactory;
    use HasPublicId;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'campaign_id',
        'name',
        'status',
        'targeting',
        'optimization_goal',
        'bid_strategy',
        'bid_amount',
        'budget_amount',
        'starts_at',
        'ends_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AdSetStatus::class,
            'bid_strategy' => BidStrategy::class,
            'bid_amount' => 'integer',
            'budget_amount' => 'integer',
            'targeting' => 'array',
            'metadata' => 'array',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
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
     * @return HasMany<Ad, $this>
     */
    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
    }

    /**
     * Targeting always goes through the value object, so a stored document
     * nobody can parse stops publishing rather than being sent to a provider
     * as an empty audience.
     */
    public function targeting(): Targeting
    {
        return Targeting::fromArray($this->targeting ?? []);
    }

    public function setTargeting(Targeting $targeting): void
    {
        $this->targeting = $targeting->toArray();
    }

    public function bidAmount(?Currency $currency = null): ?Money
    {
        if ($this->bid_amount === null) {
            return null;
        }

        return Money::ofMinor($this->bid_amount, $currency ?? $this->campaign->currency());
    }

    public function isPublished(): bool
    {
        return $this->provider_ad_set_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'status' => $this->status->value,
            'bid_strategy' => $this->bid_strategy->value,
        ];
    }

    protected static function newFactory(): AdSetFactory
    {
        return AdSetFactory::new();
    }
}
