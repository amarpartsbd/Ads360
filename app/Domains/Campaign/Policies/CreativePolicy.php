<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Policies;

use App\Domains\Campaign\Models\Creative;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Services\TenantContext;

/**
 * Authorization for the creative library (spec §23, §68).
 */
final class CreativePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::CreativesView);
        }

        return $user->hasPermissionTo(Permission::CreativesView, $this->context->organization());
    }

    public function view(User $user, Creative $creative): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::CreativesView);
        }

        return $this->actsWithin($user, $creative)
            && $user->hasPermissionTo(Permission::CreativesView, $this->context->organization());
    }

    /** Downloading the bytes. Audited, because it is client-owned content. */
    public function download(User $user, Creative $creative): bool
    {
        return $this->view($user, $creative);
    }

    public function create(User $user): bool
    {
        if ($user->isPlatformUser()) {
            return false;
        }

        return $user->hasPermissionTo(Permission::CreativesUpload, $this->context->organization());
    }

    /**
     * A creative an ad is running cannot be removed: deleting it would break
     * the live ad at the provider. The action checks `isInUse()` as well; this
     * is the authorization half.
     */
    public function delete(User $user, Creative $creative): bool
    {
        if ($user->isPlatformUser()) {
            return false;
        }

        return ! $creative->isInUse()
            && $this->actsWithin($user, $creative)
            && $user->hasPermissionTo(Permission::CreativesDelete, $this->context->organization());
    }

    private function actsWithin(User $user, Creative $creative): bool
    {
        $organization = $this->context->organization();

        return $user->tenant_id !== null
            && $user->tenant_id === $creative->tenant_id
            && $organization !== null
            && $organization->getKey() === $creative->organization_id
            && $user->belongsToOrganization($organization);
    }
}
