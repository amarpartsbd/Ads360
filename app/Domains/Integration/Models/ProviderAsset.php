<?php

declare(strict_types=1);

namespace App\Domains\Integration\Models;

use App\Domains\Advertising\Enums\AssetType;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Integration\Enums\AssetStatus;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Domains\Tenant\Models\Organization;
use App\Support\Concerns\HasPublicId;
use Database\Factories\ProviderAssetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One advertising asset a client has authorised (spec §15).
 *
 * @property AssetType $type
 * @property AssetStatus $status
 * @property Provider $provider
 */
class ProviderAsset extends Model
{
    /** @use HasFactory<ProviderAssetFactory> */
    use BelongsToTenant;

    use HasFactory;
    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'provider_connection_id',
        'provider',
        'type',
        'external_id',
        'name',
        'currency',
        'timezone',
        'provider_status',
        'status',
        'metadata',
        'last_seen_at',
        'unavailable_since',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'type' => AssetType::class,
            'status' => AssetStatus::class,
            'metadata' => 'array',
            'last_seen_at' => 'immutable_datetime',
            'unavailable_since' => 'immutable_datetime',
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
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Assets a campaign may actually be published against: usable in their own
     * right, and behind a connection that still works.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->where('status', AssetStatus::Available)
            ->whereHas('connection', fn (Builder $connection) => $connection
                ->whereNull('revoked_at')
                ->whereIn('status', ['CONNECTED', 'EXPIRING']));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, AssetType $type): Builder
    {
        return $query->where('type', $type);
    }

    public function isUsable(): bool
    {
        return $this->status->isUsable();
    }

    public function canBeAdIdentity(): bool
    {
        return $this->type->canBeAdIdentity() && $this->isUsable();
    }

    protected static function newFactory(): ProviderAssetFactory
    {
        return ProviderAssetFactory::new();
    }
}
