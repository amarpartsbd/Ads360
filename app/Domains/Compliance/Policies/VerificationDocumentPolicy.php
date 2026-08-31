<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Policies;

use App\Domains\Compliance\Models\VerificationDocument;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Services\TenantContext;

/**
 * Authorization for KYC document access (spec §55, §68).
 *
 * Identity documents are the most sensitive data the platform holds, so every
 * read is authorized here and every download is audited. A client sees only
 * their own organization's files; platform staff need `clients.view`.
 */
final class VerificationDocumentPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user, VerificationDocument $document): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::ClientsView);
        }

        return $this->actsWithin($user, $document)
            && $user->hasPermissionTo(Permission::ClientsView, $this->context->organization());
    }

    public function download(User $user, VerificationDocument $document): bool
    {
        return $this->view($user, $document);
    }

    /**
     * A client may withdraw a document only while the submission is still
     * theirs to change. Once it is in the queue, removing evidence out from
     * under a reviewer is not allowed — and platform staff never delete a
     * client's evidence at all.
     */
    public function delete(User $user, VerificationDocument $document): bool
    {
        if ($user->isPlatformUser()) {
            return false;
        }

        $profile = $document->profile()->first();

        return $profile !== null
            && $profile->isEditableByClient()
            && $this->actsWithin($user, $document)
            && $user->hasPermissionTo(Permission::ClientsUpdate, $this->context->organization());
    }

    private function actsWithin(User $user, VerificationDocument $document): bool
    {
        $organization = $this->context->organization();

        return $user->tenant_id !== null
            && $user->tenant_id === $document->tenant_id
            && $organization !== null
            && $organization->getKey() === $document->organization_id
            && $user->canReachOrganization($organization);
    }
}
