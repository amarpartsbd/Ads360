<?php

declare(strict_types=1);

namespace App\Domains\Audit\Policies;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Services\TenantContext;

/**
 * Audit logs are readable by those who hold `audit.view`, and writable by
 * nobody (spec §51).
 *
 * Update and delete return false unconditionally — including for platform
 * administrators — so the trail cannot be edited from inside the application.
 * The model enforces the same rule again at the persistence layer.
 */
final class AuditLogPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::AuditView, $this->context->organization());
    }

    public function view(User $user, AuditLog $log): bool
    {
        if (! $user->hasPermissionTo(Permission::AuditView, $this->context->organization())) {
            return false;
        }

        if ($user->isPlatformUser()) {
            return true;
        }

        // A tenant may only read entries recorded against its own tenant.
        return $log->tenant_id !== null && $log->tenant_id === $user->tenant_id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $log): bool
    {
        return false;
    }
}
