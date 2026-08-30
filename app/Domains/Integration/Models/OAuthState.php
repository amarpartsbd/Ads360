<?php

declare(strict_types=1);

namespace App\Domains\Integration\Models;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use OAuth state token (spec §16).
 *
 * Only the hash is stored. The value itself exists in the redirect URL and in
 * the callback, so a leaked table cannot be used to forge one.
 *
 * @property Provider $provider
 */
class OAuthState extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'oauth_states';

    protected $fillable = [
        'state_hash',
        'tenant_id',
        'organization_id',
        'user_id',
        'provider',
        'redirect_to',
        'scopes',
        'expires_at',
        'ip_address',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['state_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'scopes' => 'array',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $state): string
    {
        return hash('sha256', $state);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isRedeemable(): bool
    {
        return $this->consumed_at === null && ! $this->hasExpired();
    }
}
