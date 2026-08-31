<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Models;

use App\Domains\Campaign\Enums\AdSetStatus;
use App\Domains\Integration\Models\ProviderAsset;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Database\Factories\AdFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One ad (spec §21, §23).
 *
 * The identity an ad runs under is a ProviderAsset the client authorised —
 * their page, their account. The platform never lends its own identity to a
 * client's advertising.
 *
 * @property AdSetStatus $status
 */
class Ad extends Model
{
    /** @use HasFactory<AdFactory> */
    use BelongsToTenant;

    use HasFactory;
    use HasPublicId;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'campaign_id',
        'ad_set_id',
        'name',
        'status',
        'creative_id',
        'identity_asset_id',
        'headline',
        'primary_text',
        'description',
        'extra_headlines',
        'extra_descriptions',
        'call_to_action',
        'destination_url',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AdSetStatus::class,
            'extra_headlines' => 'array',
            'extra_descriptions' => 'array',
            'metadata' => 'array',
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
     * @return BelongsTo<AdSet, $this>
     */
    public function adSet(): BelongsTo
    {
        return $this->belongsTo(AdSet::class);
    }

    /**
     * @return BelongsTo<Creative, $this>
     */
    public function creative(): BelongsTo
    {
        return $this->belongsTo(Creative::class);
    }

    /**
     * @return BelongsTo<ProviderAsset, $this>
     */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(ProviderAsset::class, 'identity_asset_id');
    }

    public function isPublished(): bool
    {
        return $this->provider_ad_id !== null;
    }

    /**
     * Whether the ad has everything a provider needs. Checked before
     * submission rather than discovered at publish time, when the budget is
     * already held.
     */
    public function isComplete(): bool
    {
        return $this->creative_id !== null
            && $this->identity_asset_id !== null
            && trim((string) $this->headline) !== ''
            && trim((string) $this->primary_text) !== ''
            && trim((string) $this->destination_url) !== '';
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
            'headline' => $this->headline,
        ];
    }

    protected static function newFactory(): AdFactory
    {
        return AdFactory::new();
    }
}
