<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Models;

use App\Domains\Assistant\Enums\RecommendationKind;
use App\Domains\Assistant\Enums\RecommendationStatus;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Domains\Tenant\Models\Organization;
use App\Support\Concerns\HasPublicId;
use Database\Factories\RecommendationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the platform suggested, and what was done about it (spec §45–§47).
 *
 * @property RecommendationKind $kind
 * @property RecommendationStatus $status
 * @property array<string, mixed> $payload
 */
class Recommendation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RecommendationFactory> */
    use HasFactory;

    use HasPublicId;

    /**
     * Provenance and decision fields are absent on purpose: they are written
     * by the service that produced the row and by the action that recorded a
     * person's decision, never from request data (spec §46).
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'campaign_id',
        'kind',
        'headline',
        'body',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RecommendationKind::class,
            'status' => RecommendationStatus::class,
            'payload' => 'array',
            'decided_at' => 'immutable_datetime',
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
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Whether this was worked out from the client's own figures rather than
     * produced by a model.
     *
     * Shown beside every recommendation, because it is the single most useful
     * thing a reader can know about one.
     */
    public function isDeterministic(): bool
    {
        return $this->source_driver === 'deterministic';
    }

    /**
     * A description of where this came from, safe to show anyone.
     *
     * @return array<string, string>
     */
    public function provenance(): array
    {
        return [
            'driver' => (string) $this->source_driver,
            'model' => (string) $this->source_model,
            'version' => (string) $this->source_version,
        ];
    }

    protected static function booted(): void
    {
        static::saving(static function (self $recommendation): void {
            /*
             * PHP's empty array encodes as `[]`, which is a JSON *array*, and
             * the column holds a keyed document — an insight with nothing
             * structured to say still has a payload shaped like one. Normalised
             * here rather than at every call site, because the constraint that
             * catches it is a hundred lines away in a migration.
             */
            $payload = $recommendation->getAttribute('payload');

            if ($payload === null || $payload === []) {
                $recommendation->attributes['payload'] = '{}';
            }
        });
    }

    protected static function newFactory(): RecommendationFactory
    {
        return RecommendationFactory::new();
    }
}
