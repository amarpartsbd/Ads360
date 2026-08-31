<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Models;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Campaign\Enums\PublicationOperation;
use App\Domains\Campaign\Enums\PublicationStatus;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One attempt to change something at a provider (Rule 17).
 *
 * Rows are append-only in spirit: an attempt is created before the provider is
 * called and then settled, never removed. `booted()` refuses deletion outright,
 * because the record of what was sent is what makes a duplicate detectable
 * (spec §62).
 *
 * @property PublicationOperation $operation
 * @property PublicationStatus $status
 * @property Provider $provider
 */
class CampaignPublication extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = [
        'campaign_id',
        'publishable_type',
        'publishable_id',
        'provider',
        'operation',
        'idempotency_key',
        'status',
        'provider_reference',
        'attempts',
        'last_error',
        'request_snapshot',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'operation' => PublicationOperation::class,
            'status' => PublicationStatus::class,
            'attempts' => 'integer',
            'request_snapshot' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new \RuntimeException(
                'Publication records cannot be deleted. They are the evidence that a '
                .'provider request was made, and deleting one would let the same '
                .'request run again.'
            );
        });
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function publishable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * A fresh idempotency key.
     *
     * Random rather than derived from the entity: a derived key would be the
     * same on a deliberate second attempt, and there are operations — pausing,
     * resuming — where a second attempt is exactly what is wanted. What stops
     * a duplicate *creation* is the unique index on succeeded creations, not
     * the shape of the key.
     */
    public static function newKey(): string
    {
        return (string) Str::ulid();
    }

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'operation' => $this->operation->value,
            'status' => $this->status->value,
            'provider_reference' => $this->provider_reference,
            'attempts' => $this->attempts,
            'last_error' => $this->last_error,
        ];
    }
}
