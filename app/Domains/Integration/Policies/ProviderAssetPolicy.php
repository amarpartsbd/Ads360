<?php

declare(strict_types=1);

namespace App\Domains\Integration\Policies;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Integration\Models\ProviderAsset;
use App\Domains\Tenant\Services\TenantContext;

/**
 * Authorization for connected advertising assets (spec §15, §68).
 */
final class ProviderAssetPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::AssetsView);
        }

        return $user->hasPermissionTo(Permission::AssetsView, $this->context->organization());
    }

    public function view(User $user, ProviderAsset $asset): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::AssetsView);
        }

        return $this->actsWithin($user, $asset)
            && $user->hasPermissionTo(Permission::AssetsView, $this->context->organization());
    }

    /**
     * Assets are discovered from the provider, never created by hand — writing
     * one would claim an authorization the client never gave.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /** Taking an asset out of use here, without touching it at the provider. */
    public function disable(User $user, ProviderAsset $asset): bool
    {
        if ($user->isPlatformUser()) {
            return false;
        }

        return $this->actsWithin($user, $asset)
            && $user->hasPermissionTo(Permission::AssetsDisconnect, $this->context->organization());
    }

    public function delete(User $user, ProviderAsset $asset): bool
    {
        return false;
    }

    private function actsWithin(User $user, ProviderAsset $asset): bool
    {
        $organization = $this->context->organization();

        return $user->tenant_id !== null
            && $user->tenant_id === $asset->tenant_id
            && $organization !== null
            && $organization->getKey() === $asset->organization_id
            && $user->canReachOrganization($organization);
    }
}
