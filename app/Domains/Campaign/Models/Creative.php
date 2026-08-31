<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Models;

use App\Domains\Campaign\Enums\CreativeType;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Domains\Tenant\Models\Organization;
use App\Support\Concerns\HasPublicId;
use Database\Factories\CreativeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An uploaded image or video (spec §23).
 *
 * The storage path is `$hidden`: it is a location on a private disk, and
 * putting it in a prop would invite someone to try fetching it directly. The
 * only way to the bytes is the authorised download route.
 *
 * @property CreativeType $type
 */
class Creative extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CreativeFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'name',
        'type',
        'storage_path',
        'media_type',
        'byte_size',
        'width',
        'height',
        'duration_seconds',
        'checksum',
        'status',
        'metadata',
        'uploaded_by',
    ];

    /** @var list<string> */
    protected $hidden = [
        'storage_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CreativeType::class,
            'byte_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'integer',
            'metadata' => 'array',
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
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return HasMany<Ad, $this>
     */
    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
    }

    /** Whether any ad is still relying on this file. */
    public function isInUse(): bool
    {
        return $this->ads()->exists();
    }

    public function dimensions(): ?string
    {
        return $this->width === null || $this->height === null
            ? null
            : "{$this->width}×{$this->height}";
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'type' => $this->type->value,
            'media_type' => $this->media_type,
            'byte_size' => $this->byte_size,
            'checksum' => $this->checksum,
        ];
    }

    protected static function newFactory(): CreativeFactory
    {
        return CreativeFactory::new();
    }
}
