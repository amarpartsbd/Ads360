<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Models;

use App\Domains\Client\Enums\DocumentMediaType;
use App\Domains\Compliance\Enums\DocumentStatus;
use App\Domains\Compliance\Enums\DocumentType;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Domains\Tenant\Models\Organization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One evidence file attached to a verification profile.
 *
 * `path` and `disk` locate private bytes and are never exposed to a client
 * response; the interface addresses a document by its `public_id` and the
 * download route resolves it after authorizing the read (spec §55).
 *
 * @property DocumentType $type
 * @property DocumentStatus $status
 */
class VerificationDocument extends Model
{
    use BelongsToTenant;
    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'type',
        'disk',
        'path',
        'original_filename',
        'media_type',
        'size_bytes',
        'checksum',
        'width',
        'height',
        'status',
        'review_note',
        'uploaded_by',
    ];

    /**
     * Storage location is stripped from any array or JSON representation, so a
     * document cannot leak its object key through a serialised response.
     *
     * @var list<string>
     */
    protected $hidden = ['disk', 'path', 'checksum'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<VerificationProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(VerificationProfile::class, 'verification_profile_id');
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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function mediaType(): ?DocumentMediaType
    {
        return DocumentMediaType::tryFrom($this->media_type);
    }

    public function isImage(): bool
    {
        return $this->mediaType()?->isImage() ?? false;
    }

    /** A human-readable size for the interface. */
    public function humanSize(): string
    {
        $bytes = $this->size_bytes;

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1_048_576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1_048_576, 1).' MB';
    }
}
