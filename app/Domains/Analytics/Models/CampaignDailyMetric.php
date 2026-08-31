<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Models;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Campaign\Models\Ad;
use App\Domains\Campaign\Models\AdSet;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Support\Values\Currency;
use App\Support\Values\Money;
use Database\Factories\CampaignDailyMetricFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One day of a campaign's performance (spec §38).
 *
 * A row is the provider's latest word on a day, not a running total: ingestion
 * upserts, because attribution windows move spend onto days already reported.
 *
 * @property Provider $provider
 * @property int $spend
 * @property int $impressions
 * @property int $clicks
 * @property int $reach
 * @property int $conversions
 * @property int $conversion_value
 */
class CampaignDailyMetric extends Model
{
    /** @use HasFactory<CampaignDailyMetricFactory> */
    use BelongsToTenant;

    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'campaign_id',
        'ad_set_id',
        'ad_id',
        'provider',
        'metric_date',
        'currency',
        'spend',
        'impressions',
        'clicks',
        'reach',
        'conversions',
        'conversion_value',
        'reported_at',
        'metadata',
    ];

    /** @var array<string, int> */
    protected $attributes = [
        'spend' => 0,
        'impressions' => 0,
        'clicks' => 0,
        'reach' => 0,
        'conversions' => 0,
        'conversion_value' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'metric_date' => 'immutable_date',
            'spend' => 'integer',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'reach' => 'integer',
            'conversions' => 'integer',
            'conversion_value' => 'integer',
            'reported_at' => 'immutable_datetime',
            'metadata' => 'array',
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
     * @return BelongsTo<AdSet, $this>
     */
    public function adSet(): BelongsTo
    {
        return $this->belongsTo(AdSet::class);
    }

    /**
     * @return BelongsTo<Ad, $this>
     */
    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }

    public function currency(): Currency
    {
        return Currency::of($this->currency);
    }

    public function spendAmount(): Money
    {
        return Money::ofMinor($this->spend, $this->currency());
    }

    public function conversionValue(): Money
    {
        return Money::ofMinor($this->conversion_value, $this->currency());
    }

    /**
     * Rows for the whole campaign rather than for one ad set or ad.
     *
     * Both nulls, so the campaign total is not double-counted by summing rows
     * at two levels at once.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCampaignLevel(Builder $query): Builder
    {
        return $query->whereNull('ad_set_id')->whereNull('ad_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()]);
    }

    protected static function newFactory(): CampaignDailyMetricFactory
    {
        return CampaignDailyMetricFactory::new();
    }
}
