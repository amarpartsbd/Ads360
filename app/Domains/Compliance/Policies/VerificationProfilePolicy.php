<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Policies;

use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Services\TenantContext;

/**
 * Authorization for business verification (spec §11, §68).
 *
 * The rule that matters most: a client can prepare and submit their own
 * verification, but can never decide it. Review is platform-only, so no
 * combination of client permissions adds up to self-verification.
 */
final class VerificationProfilePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::ClientsView);
        }

        return $user->hasPermissionTo(Permission::ClientsView, $this->context->organization());
    }

    public function view(User $user, VerificationProfile $profile): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::ClientsView);
        }

        return $this->actsWithin($user, $profile)
            && $user->hasPermissionTo(Permission::ClientsView, $this->context->organization());
    }

    /** Editing and submitting are the client's own responsibility. */
    public function update(User $user, VerificationProfile $profile): bool
    {
        // Platform staff do not fill in a client's declaration on their behalf:
        // the submission has to be the client's own statement.
        if ($user->isPlatformUser()) {
            return false;
        }

        return $this->actsWithin($user, $profile)
            && $profile->isEditableByClient()
            && $user->hasPermissionTo(Permission::ClientsUpdate, $this->context->organization());
    }

    public function submit(User $user, VerificationProfile $profile): bool
    {
        return $this->update($user, $profile);
    }

    /**
     * Deciding a submission. Platform-only and gated on `clients.verify`, which
     * no client or agency role holds.
     */
    public function review(User $user, VerificationProfile $profile): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::ClientsVerify);
    }

    /** Withdrawing verification is a suspension, and gated separately. */
    public function suspend(User $user, VerificationProfile $profile): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::ClientsSuspend);
    }

    private function actsWithin(User $user, VerificationProfile $profile): bool
    {
        $organization = $this->context->organization();

        return $user->tenant_id !== null
            && $user->tenant_id === $profile->tenant_id
            && $organization !== null
            && $organization->getKey() === $profile->organization_id
            && $user->canReachOrganization($organization);
    }
}
