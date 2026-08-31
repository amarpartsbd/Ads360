<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\Permission as PermissionEnum;
use App\Domains\Identity\Enums\RoleScope;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Tenant\Enums\MembershipStatus;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\OrganizationUser;
use App\Domains\Tenant\Models\Tenant;
use App\Support\Concerns\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A platform identity.
 *
 * A user belongs to at most one tenant. Platform staff have no tenant and are
 * flagged `is_platform_user`; everyone else reaches data only through the
 * organizations they are an active member of.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $tenant_id
 * @property bool $is_platform_user
 * @property string $email
 * @property UserStatus $status
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasPublicId;
    use Notifiable;
    use SoftDeletes;
    use TwoFactorAuthenticatable;

    protected $fillable = [
        'tenant_id',
        'is_platform_user',
        'name',
        'email',
        'mobile_number',
        'password',
        'status',
        'timezone',
        'locale',
        'terms_accepted_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Resolved permission set, memoised per request.
     *
     * @var Collection<int, string>|null
     */
    private ?Collection $permissionCache = null;

    private ?int $permissionCacheOrganizationId = null;

    /** Memoised for the request: agency reach is asked once per policy call. */
    private ?bool $tenantWideGrants = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'is_platform_user' => 'boolean',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'terms_accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsToMany<Organization, $this, OrganizationUser>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->using(OrganizationUser::class)
            ->withPivot(['id', 'tenant_id', 'status', 'is_primary', 'invited_by', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Memberships that actually grant access. Invited, suspended and revoked
     * memberships deliberately do not.
     *
     * @return BelongsToMany<Organization, $this, OrganizationUser>
     */
    public function activeOrganizations(): BelongsToMany
    {
        return $this->organizations()->wherePivot('status', MembershipStatus::Active->value);
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withPivot(['organization_id', 'tenant_id', 'granted_by'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<LoginHistory, $this>
     */
    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class);
    }

    /**
     * @return HasMany<UserDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function isPlatformUser(): bool
    {
        return $this->is_platform_user;
    }

    /**
     * Where this account belongs once it has signed in.
     *
     * The two surfaces are mutually exclusive: platform staff hold no tenant and
     * are refused by the client application, and a client account has no
     * business in the administration area. A single configured destination
     * therefore cannot be right for both — it sends one of them to a page that
     * refuses them, which is what a fresh administrator met on their first
     * sign-in.
     *
     * Only the surface is decided here. Whether the account may proceed once it
     * arrives — verified, enrolled in two-factor, holding a workspace — stays
     * with the middleware on the route, so this cannot become a second place
     * where access is decided.
     */
    public function homeRoute(): string
    {
        return $this->isPlatformUser()
            ? route('admin.dashboard')
            : route('client.dashboard');
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function canAuthenticate(): bool
    {
        return $this->status->canAuthenticate() && ! $this->isLocked();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Whether this user holds a permission, optionally within one organization.
     *
     * Platform-scoped grants apply everywhere the user is entitled to act;
     * organization-scoped grants apply only to the organization named on the
     * grant. Nothing here consults a role name.
     */
    public function hasPermissionTo(PermissionEnum|string $permission, ?Organization $organization = null): bool
    {
        $value = $permission instanceof PermissionEnum ? $permission->value : $permission;

        return $this->permissionsFor($organization)->contains($value);
    }

    /**
     * @param  iterable<PermissionEnum|string>  $permissions
     */
    public function hasAnyPermission(iterable $permissions, ?Organization $organization = null): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermissionTo($permission, $organization)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The permission names this user holds in the given organization.
     *
     * @return Collection<int, string>
     */
    public function permissionsFor(?Organization $organization = null): Collection
    {
        $organizationId = $organization?->getKey();

        if ($this->permissionCache !== null && $this->permissionCacheOrganizationId === $organizationId) {
            return $this->permissionCache;
        }

        $permissions = $this->roles()
            ->where(function ($query) use ($organizationId): void {
                // Grants with no organization are platform- or tenant-wide.
                $query->whereNull('role_user.organization_id');

                if ($organizationId !== null) {
                    $query->orWhere('role_user.organization_id', $organizationId);
                }
            })
            ->with('permissions:id,name')
            ->get()
            ->flatMap(fn (Role $role): Collection => $role->permissions->pluck('name'))
            ->unique()
            ->values();

        $this->permissionCache = $permissions;
        $this->permissionCacheOrganizationId = $organizationId;

        return $permissions;
    }

    /** Drop the memoised permission set after a role change. */
    public function forgetCachedPermissions(): void
    {
        $this->permissionCache = null;
        $this->permissionCacheOrganizationId = null;
        $this->tenantWideGrants = null;
        $this->unsetRelation('roles');
    }

    /**
     * The active membership for an organization, or null when there is none.
     * This is the check that decides whether a user may enter an organization.
     */
    public function membershipIn(Organization $organization): ?OrganizationUser
    {
        /** @var Organization|null $match */
        $match = $this->organizations()
            ->wherePivot('organization_id', $organization->getKey())
            ->first();

        $pivot = $match?->getRelationValue('pivot');

        return $pivot instanceof OrganizationUser && $pivot->grantsAccess() ? $pivot : null;
    }

    public function belongsToOrganization(Organization $organization): bool
    {
        return $this->membershipIn($organization) !== null;
    }

    /**
     * Whether this user's authority covers every organization of their tenant
     * (spec §42).
     *
     * True for an agency owner or agency admin: their role is granted at
     * TENANT scope, which means "this agency", not "this one client". It is
     * false for a client owner, whose role is granted at ORGANIZATION scope
     * however senior it sounds, and false for platform staff, who are unscoped
     * and reach clients through the admin surface instead.
     *
     * The grant is checked against *this user's own* tenant. A tenant-wide
     * grant carrying another tenant's id — which nothing should ever write —
     * still buys nothing here.
     */
    public function actsAcrossTenant(): bool
    {
        if ($this->tenant_id === null || $this->isPlatformUser()) {
            return false;
        }

        return $this->tenantWideGrants ??= $this->roles()
            ->where('roles.scope', RoleScope::Tenant->value)
            ->wherePivotNull('organization_id')
            ->wherePivot('tenant_id', $this->tenant_id)
            ->exists();
    }

    /**
     * Whether this user may act inside an organization at all.
     *
     * Two ways in, and the tenant check gates both:
     *
     *   1. an active membership in that specific organization — every client
     *      user, and agency staff assigned to one client;
     *   2. a tenant-wide grant — an agency owner, for whom a membership row
     *      per client would mean revocation had to find them all.
     *
     * This is *reach*, not permission. Holding it means a permission check is
     * worth making; it never grants one on its own.
     */
    public function canReachOrganization(Organization $organization): bool
    {
        if ($this->tenant_id === null || $this->tenant_id !== $organization->tenant_id) {
            return false;
        }

        return $this->belongsToOrganization($organization) || $this->actsAcrossTenant();
    }

    /**
     * Every organization this user may act inside.
     *
     * Deliberately a query rather than a collection: an agency with hundreds
     * of clients should paginate rather than load them all to render a picker.
     *
     * @return Builder<Organization>
     */
    public function reachableOrganizations(): Builder
    {
        if ($this->actsAcrossTenant()) {
            return Organization::query()->where('tenant_id', $this->tenant_id);
        }

        /*
         * The membership path returns the same model, so a caller can treat
         * both the same way — but it goes through the pivot, which is what
         * excludes an invited, suspended or revoked membership.
         */
        return Organization::query()->whereIn(
            'organizations.id',
            $this->activeOrganizations()->select('organizations.id'),
        );
    }

    /** True when the user holds any role at PLATFORM scope. */
    public function hasPlatformRole(): bool
    {
        return $this->roles()->where('roles.scope', RoleScope::Platform->value)->exists();
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
