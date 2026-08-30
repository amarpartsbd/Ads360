<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Models;

use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Domains\Tenant\Enums\InvitationStatus;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An outstanding invitation to join an organization (spec §82).
 *
 * The plaintext token is never stored — only its SHA-256. Acceptance hashes the
 * presented token and looks the row up by that, so the database holds nothing
 * that could be replayed.
 *
 * @property InvitationStatus $status
 */
class OrganizationInvitation extends Model
{
    use BelongsToTenant;
    use HasPublicId;

    protected $fillable = [
        'organization_id',
        'email',
        'name',
        'role_id',
        'token_hash',
        'status',
        'invited_by',
        'expires_at',
        'last_sent_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_sent_at' => 'immutable_datetime',
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
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * An invitation is usable only while it is pending and unexpired. Expiry is
     * evaluated at read time, so a row that was never swept still cannot be
     * redeemed.
     */
    public function isRedeemable(): bool
    {
        return $this->status->isOpen() && ! $this->hasExpired();
    }

    /** Hashes a presented token the same way the stored hash was produced. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Finds a redeemable invitation for a presented token.
     *
     * Deliberately unscoped by tenant: the person accepting is typically not
     * authenticated yet, so there is no context to scope by. The token itself
     * is the credential, and the accepting action re-checks everything else.
     *
     * @return Builder<self>
     */
    public static function forToken(string $token): Builder
    {
        return self::query()
            ->withoutGlobalScopes()
            ->where('token_hash', self::hashToken($token))
            ->where('status', InvitationStatus::Pending)
            ->where('expires_at', '>', Carbon::now());
    }
}
