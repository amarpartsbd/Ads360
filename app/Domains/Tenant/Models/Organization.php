<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Domains\Tenant\Enums\MembershipStatus;
use App\Domains\Tenant\Enums\OrganizationStatus;
use App\Support\Concerns\HasPublicId;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The advertiser account users work inside (spec §5).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property string $name
 * @property OrganizationStatus $status
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
            'settings' => 'array',
            'activated_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
        ];
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

    public function isOperational(): bool
    {
        return $this->status->isOperational();
    }

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }
}
