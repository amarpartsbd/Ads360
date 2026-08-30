<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Scopes;

use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-owned model to the bound tenant (spec §5).
 *
 * This is the first of three independent defences against cross-tenant access;
 * the others are authorization policies and explicit ownership checks in route
 * model binding. Any one of them failing must not be enough to leak data, which
 * is why all three exist and are tested separately.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->hasTenant()) {
            // No tenant bound. Platform staff and console commands query across
            // tenants deliberately; client requests can never reach this state
            // because EnsureTenantContext refuses them first.
            return;
        }

        $builder->where(
            $model->qualifyColumn('tenant_id'),
            $context->tenantId()
        );
    }
}
