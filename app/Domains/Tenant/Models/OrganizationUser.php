<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Models;

use App\Domains\Tenant\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Membership of a user in an organization.
 *
 * The pivot carries `tenant_id` as well, so a membership row states the tenant
 * it belongs to on its own and tenant resolution never needs a join it could
 * get wrong.
 *
 * @property int $organization_id
 * @property int $user_id
 * @property int $tenant_id
 * @property MembershipStatus $status
 */
class OrganizationUser extends Pivot
{
    protected $table = 'organization_user';

    public $incrementing = true;

    protected $fillable = [
        'organization_id',
        'user_id',
        'tenant_id',
        'status',
        'is_primary',
        'invited_by',
        'invited_at',
        'joined_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'is_primary' => 'boolean',
            'invited_at' => 'immutable_datetime',
            'joined_at' => 'immutable_datetime',
        ];
    }

    public function grantsAccess(): bool
    {
        return $this->status->grantsAccess();
    }
}
