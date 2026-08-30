<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device a user has signed in from, and whether they have marked it trusted
 * (spec §8).
 *
 * The fingerprint is a hash: no raw device or browser identifier is stored.
 *
 * @property int $user_id
 * @property string $fingerprint
 */
class UserDevice extends Model
{
    use HasPublicId;

    protected $fillable = [
        'user_id',
        'name',
        'fingerprint',
        'platform',
        'browser',
        'trusted_at',
        'trust_expires_at',
        'last_used_at',
        'last_ip_address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trusted_at' => 'immutable_datetime',
            'trust_expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTrusted(): bool
    {
        if ($this->trusted_at === null) {
            return false;
        }

        return $this->trust_expires_at === null || $this->trust_expires_at->isFuture();
    }
}
