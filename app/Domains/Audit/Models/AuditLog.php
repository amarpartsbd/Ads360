<?php

declare(strict_types=1);

namespace App\Domains\Audit\Models;

use App\Domains\Audit\Enums\ActorType;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * An immutable audit record (spec §51).
 *
 * The model refuses updates and deletes outright rather than relying on nobody
 * calling them. Corrections are made by writing a new record, never by editing
 * an old one.
 *
 * @property AuditAction|string $action
 * @property array<string, mixed>|null $before_data
 * @property array<string, mixed>|null $after_data
 */
class AuditLog extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'actor_type',
        'actor_label',
        'tenant_id',
        'organization_id',
        'action',
        'resource_type',
        'resource_id',
        'before_data',
        'after_data',
        'context',
        'ip_address',
        'user_agent',
        'request_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'before_data' => 'array',
            'after_data' => 'array',
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Audit log entries are immutable and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Audit log entries are immutable and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
