<?php

declare(strict_types=1);

namespace App\Domains\Integration\Policies;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Integration\Models\ProviderConnection;
use App\Domains\Tenant\Services\TenantContext;

/**
 * Authorization for a client's provider connections (spec §16, §68).
 *
 * The third check in every method is the one that matters: membership of the
 * organization the connection belongs to, tested against the context resolved
 * server-side rather than anything the request supplied (spec §7).
 */
final class ProviderConnectionPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::AssetsView);
        }

        return $user->hasPermissionTo(Permission::AssetsView, $this->context->organization());
    }

    public function view(User $user, ProviderConnection $connection): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::AssetsView);
        }

        return $this->actsWithin($user, $connection)
            && $user->hasPermissionTo(Permission::AssetsView, $this->context->organization());
    }

    /**
     * Starting an authorization flow. Platform staff are excluded on purpose:
     * a connection is a grant made by the client, in their own provider
     * session, and staff acting here would be authorising on someone's behalf.
     */
    public function create(User $user): bool
    {
        if ($user->isPlatformUser()) {
            return false;
        }

        return $user->hasPermissionTo(Permission::AssetsConnect, $this->context->organization());
    }

    public function refresh(User $user, ProviderConnection $connection): bool
    {
        if ($user->isPlatformUser()) {
            return false;
        }

        return $this->actsWithin($user, $connection)
            && $user->hasPermissionTo(Permission::AssetsConnect, $this->context->organization());
    }

    public function disconnect(User $user, ProviderConnection $connection): bool
    {
        if ($user->isPlatformUser()) {
            return false;
        }

        return $this->actsWithin($user, $connection)
            && $user->hasPermissionTo(Permission::AssetsDisconnect, $this->context->organization());
    }

    /**
     * The row stays after disconnection: campaigns and audit entries point at
     * it, and spec §62 keeps records other records depend on.
     */
    public function delete(User $user, ProviderConnection $connection): bool
    {
        return false;
    }

    private function actsWithin(User $user, ProviderConnection $connection): bool
    {
        $organization = $this->context->organization();

        return $user->tenant_id !== null
            && $user->tenant_id === $connection->tenant_id
            && $organization !== null
            && $organization->getKey() === $connection->organization_id
            && $user->canReachOrganization($organization);
    }
}
