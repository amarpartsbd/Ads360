<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Models;

use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\TenantStatus;
use App\Domains\Tenant\Enums\TenantType;
use App\Domains\Tenant\Values\Branding;
use App\Support\Concerns\HasPublicId;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * The outermost isolation boundary (spec §5).
 *
 * Tenant itself carries no TenantScope: it *is* the tenant. Access to a tenant
 * record is decided by policy.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property TenantType $type
 * @property TenantStatus $status
 * @property array<string, mixed> $branding
 * @property string|null $custom_domain
 * @property array<string, mixed> $settings
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'status',
        'billing_email',
        'country',
        'timezone',
        'default_currency',
        'branding',
        'settings',
        'custom_domain',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TenantType::class,
            'status' => TenantStatus::class,
            'branding' => 'array',
            'settings' => 'array',
            'suspended_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<Organization, $this>
     */
    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Roles this tenant defined for itself. System roles are shared and have no
     * tenant, so they are not returned here.
     *
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function isOperational(): bool
    {
        return $this->status->isOperational();
    }

    /**
     * Branding for white-label rendering, falling back to platform defaults so
     * no brand string is hard-coded in the UI (spec §43).
     *
     * @return array<string, mixed>
     */
    public function brandingWithDefaults(): array
    {
        return array_merge([
            'name' => config('platform.name'),
            'logo_url' => null,
            'primary_color' => null,
            'support_email' => config('platform.support_email'),
        ], $this->branding);
    }

    /**
     * The branding as a validated value object (spec §43).
     *
     * Stored branding is read back through the same object that validated it
     * on the way in, so a document written before a rule existed — or edited
     * by hand — cannot put an unreadable colour on a screen. Anything that no
     * longer passes is dropped in favour of the platform default rather than
     * rendered.
     */
    public function brandingValue(): Branding
    {
        try {
            return Branding::fromArray($this->branding ?? []);
        } catch (InvalidArgumentException) {
            return Branding::none();
        }
    }

    /** Whether this tenant is allowed to brand its own copy of the platform. */
    public function canWhiteLabel(): bool
    {
        return (bool) config('platform.features.white_label');
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
