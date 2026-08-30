<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\Permission as PermissionEnum;
use App\Domains\Identity\Enums\RoleScope;
use App\Domains\Tenant\Models\Tenant;
use App\Support\Concerns\HasPublicId;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named bundle of permissions.
 *
 * A role with a null tenant is a system role shipped with the platform and
 * shared by every tenant; a role with a tenant belongs to that tenant alone.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $slug
 * @property RoleScope $scope
 * @property bool $is_system
 * @property \Illuminate\Database\Eloquent\Collection<int, Permission> $permissions
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    use HasPublicId;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'scope',
        'description',
        'is_system',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => RoleScope::class,
            'is_system' => 'boolean',
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
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['organization_id', 'tenant_id', 'granted_by'])
            ->withTimestamps();
    }

    /**
     * Replace this role's permissions with the given set.
     *
     * @param  iterable<PermissionEnum|string>  $permissions
     */
    public function syncPermissions(iterable $permissions): void
    {
        $names = [];

        foreach ($permissions as $permission) {
            $names[] = $permission instanceof PermissionEnum ? $permission->value : $permission;
        }

        $ids = Permission::query()->whereIn('name', $names)->pluck('id');

        $this->permissions()->sync($ids);
        $this->unsetRelation('permissions');
    }

    public function isPlatformRole(): bool
    {
        return $this->scope === RoleScope::Platform;
    }

    protected static function newFactory(): RoleFactory
    {
        return RoleFactory::new();
    }
}
