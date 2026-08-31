<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Concerns;

use App\Domains\Tenant\Models\Tenant;
use App\Domains\Tenant\Scopes\TenantScope;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every model that is owned by a tenant.
 *
 * Adds the global scope and stamps `tenant_id` on create from the bound
 * context, so application code never has to set it — and cannot set it to
 * someone else's tenant by accident.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if ($model->tenant_id !== null) {
                return;
            }

            $tenantId = app(TenantContext::class)->tenantId();

            if ($tenantId !== null) {
                $model->tenant_id = $tenantId;
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Deliberately query across tenants.
     *
     * Reserved for platform administration, reconciliation and reporting. Every
     * call site is a place a reviewer should look at closely, which is why it is
     * spelled out rather than achieved by unsetting context.
     *
     * @return Builder<static>
     */
    public static function acrossTenants(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }
}
