<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One authentication attempt, successful or not (spec §8).
 *
 * Append-only. The attempted password is never recorded in any form.
 *
 * @property int|null $user_id
 * @property string $email
 * @property bool $successful
 */
class LoginHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'email',
        'successful',
        'failure_reason',
        'two_factor_used',
        'ip_address',
        'user_agent',
        'device_fingerprint',
        'country',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
            'two_factor_used' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
