<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Models;

use App\Domains\Compliance\Enums\ReviewDecision;
use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One compliance decision on a verification profile.
 *
 * Append-only, for the same reason audit records are: the history of how a case
 * was decided must not be rewritable after the fact.
 *
 * @property ReviewDecision $decision
 * @property VerificationStatus $from_status
 * @property VerificationStatus $to_status
 */
class VerificationReview extends Model
{
    use BelongsToTenant;
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = [
        'verification_profile_id',
        'organization_id',
        'reviewer_id',
        'decision',
        'from_status',
        'to_status',
        'internal_note',
        'client_message',
        'referenced_documents',
    ];

    /**
     * Staff-only reasoning. Hidden from serialisation so it cannot reach a
     * client-facing response by accident (spec §54); the admin controller
     * selects it explicitly.
     *
     * @var list<string>
     */
    protected $hidden = ['internal_note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision' => ReviewDecision::class,
            'from_status' => VerificationStatus::class,
            'to_status' => VerificationStatus::class,
            'referenced_documents' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Verification reviews are a decision history and cannot be edited.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Verification reviews are a decision history and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<VerificationProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(VerificationProfile::class, 'verification_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
