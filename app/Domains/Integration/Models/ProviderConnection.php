<?php

declare(strict_types=1);

namespace App\Domains\Integration\Models;

use App\Domains\Advertising\DTOs\ProviderCredentials;
use App\Domains\Advertising\Enums\ConnectionStatus;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Domains\Tenant\Models\Organization;
use App\Support\Concerns\HasPublicId;
use Database\Factories\ProviderConnectionFactory;
use DateTime;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A client's authorisation for the platform to act on their behalf (spec §16).
 *
 * Token handling is the whole point of this class:
 *
 *   - the two token columns are `encrypted` casts, so plaintext never reaches
 *     the database;
 *   - both are `$hidden`, so no `toArray()`, `toJson()` or Inertia prop can
 *     carry them to a browser (Rule 11);
 *   - reading one is deliberately awkward — `accessToken()` exists and nothing
 *     else does, so every read is greppable.
 *
 * @property Provider $provider
 * @property ConnectionStatus $status
 */
class ProviderConnection extends Model
{
    /** @use HasFactory<ProviderConnectionFactory> */
    use BelongsToTenant;

    use HasFactory;
    use HasPublicId;

    /**
     * Token columns are absent on purpose: they are set through
     * `storeCredentials()`, never mass assigned from request data.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'provider',
        'external_user_id',
        'account_name',
        'expires_at',
        'scopes',
        'status',
        'status_detail',
        'connected_by',
    ];

    /**
     * The last line of defence against a token reaching a response. Even if a
     * controller serialises a connection wholesale, these do not travel.
     *
     * @var list<string>
     */
    protected $hidden = [
        'access_token_encrypted',
        'refresh_token_encrypted',
        'external_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'status' => ConnectionStatus::class,
            // Encrypted at rest by the application (spec §16, §98).
            'access_token_encrypted' => 'encrypted',
            'refresh_token_encrypted' => 'encrypted',
            'scopes' => 'array',
            'expires_at' => 'immutable_datetime',
            'last_verified_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
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
     * @return HasMany<ProviderAsset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(ProviderAsset::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    /**
     * The only place plaintext credentials are written. The `encrypted` casts
     * turn them into ciphertext on the way to the database; keeping the write
     * in one method means every credential write is one grep away.
     *
     * A refresh token is only replaced when the provider issued a new one:
     * several providers return an access token alone on refresh, and taking
     * that literally would throw away the means to refresh again.
     */
    public function storeCredentials(ProviderCredentials $credentials): void
    {
        $this->access_token_encrypted = $credentials->accessToken;

        if ($credentials->refreshToken !== null) {
            $this->refresh_token_encrypted = $credentials->refreshToken;
        }

        $this->expires_at = $credentials->expiresAt !== null
            ? Carbon::instance(DateTime::createFromImmutable($credentials->expiresAt))
            : null;
    }

    /** Drops the stored credentials. Used when a grant ends (spec §16). */
    public function clearCredentials(): void
    {
        $this->access_token_encrypted = null;
        $this->refresh_token_encrypted = null;
    }

    /**
     * The access token, for handing to a provider adapter.
     *
     * The only reader of this column. Anything calling it is making a provider
     * request, which makes misuse easy to spot in review.
     */
    public function accessToken(): string
    {
        return (string) $this->access_token_encrypted;
    }

    public function hasCredentials(): bool
    {
        return $this->access_token_encrypted !== null;
    }

    public function refreshToken(): ?string
    {
        $token = $this->refresh_token_encrypted;

        return $token === null ? null : (string) $token;
    }

    public function hasRefreshToken(): bool
    {
        return $this->refresh_token_encrypted !== null;
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->status->isUsable();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether the token is close enough to expiry to be worth renewing.
     *
     * Renewed early rather than on expiry: a token that dies mid-publish costs
     * a failed campaign, and refreshing a day ahead costs one request.
     */
    public function isExpiringSoon(int $withinHours = 24): bool
    {
        return $this->expires_at !== null
            && ! $this->expires_at->isPast()
            && $this->expires_at->lessThan(Carbon::now()->addHours($withinHours));
    }

    /**
     * A description of the connection that is safe anywhere — a log line, an
     * audit payload, an API response.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'id' => $this->public_id,
            'provider' => $this->provider->value,
            'account_name' => $this->account_name,
            'status' => $this->status->value,
            'scopes' => $this->scopes,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }

    protected static function newFactory(): ProviderConnectionFactory
    {
        return ProviderConnectionFactory::new();
    }
}
