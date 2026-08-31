<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Models;

use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Domains\Tenant\Enums\MembershipStatus;
use App\Domains\Tenant\Enums\OrganizationStatus;
use App\Support\Concerns\HasPublicId;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The advertiser account users work inside (spec §5).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property string $name
 * @property OrganizationStatus $status
 * @property bool $is_house_account
 */
class Organization extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'legal_name',
        'business_type',
        'country',
        'timezone',
        'default_currency',
        'website',
        'contact_email',
        'contact_number',
        'status',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'is_house_account' => 'boolean',
            'settings' => 'array',
            'activated_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
        ];
    }

    /**
     * Whether this is the agency's own workspace rather than one of its
     * clients (spec §42).
     *
     * Not fillable, and set only when an agency is provisioned. A request that
     * could flip it would let an agency remove a client from its own client
     * list, or add itself to it.
     */
    public function isHouseAccount(): bool
    {
        return (bool) $this->is_house_account;
    }

    /**
     * The organizations an agency manages: everything under the tenant except
     * the agency itself.
     *
     * @param  Builder<Organization>  $query
     * @return Builder<Organization>
     */
    public function scopeAgencyClients(Builder $query): Builder
    {
        return $query->where('is_house_account', false);
    }

    /**
     * @return BelongsToMany<User, $this, OrganizationUser>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(OrganizationUser::class)
            ->withPivot(['id', 'tenant_id', 'status', 'is_primary', 'invited_by', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this, OrganizationUser>
     */
    public function activeMembers(): BelongsToMany
    {
        return $this->members()->wherePivot('status', MembershipStatus::Active->value);
    }

    /**
     * The organization's single business verification record (spec §11).
     *
     * @return HasOne<VerificationProfile, $this>
     */
    public function verificationProfile(): HasOne
    {
        return $this->hasOne(VerificationProfile::class);
    }

    public function isOperational(): bool
    {
        return $this->status->isOperational();
    }

    /**
     * Whether the business has been verified.
     *
     * Reads the organization's own status rather than joining to the profile:
     * the review action keeps the two in step, so this stays a cheap check on
     * every request that needs to know.
     */
    public function isVerified(): bool
    {
        return $this->status === OrganizationStatus::Active;
    }

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }
}
