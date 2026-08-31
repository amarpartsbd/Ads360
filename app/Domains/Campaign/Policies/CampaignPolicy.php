<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Policies;

use App\Domains\Campaign\Models\Campaign;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Services\TenantContext;

/**
 * Authorization for campaigns (spec §21, §68).
 *
 * The split that matters here is not client versus staff but *who decides*.
 * A client builds and submits; only the platform approves, rejects or
 * publishes. A client who could approve their own campaign could spend their
 * balance without anyone at the platform having looked at it, which is the
 * separation of duties §25 exists to keep.
 */
final class CampaignPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::CampaignsView);
        }

        return $user->hasPermissionTo(Permission::CampaignsView, $this->context->organization());
    }

    public function view(User $user, Campaign $campaign): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::CampaignsView);
        }

        return $this->actsWithin($user, $campaign)
            && $user->hasPermissionTo(Permission::CampaignsView, $this->context->organization());
    }

    public function create(User $user): bool
    {
        if ($user->isPlatformUser()) {
            return false;
        }

        return $user->hasPermissionTo(Permission::CampaignsCreate, $this->context->organization());
    }

    /**
     * Editing stops once a campaign is in review. Changing a campaign a
     * reviewer is looking at would mean approving something other than what
     * was read.
     */
    public function update(User $user, Campaign $campaign): bool
    {
        if ($user->isPlatformUser()) {
            return false;
        }

        return $campaign->isEditable()
            && $this->actsWithin($user, $campaign)
            && $user->hasPermissionTo(Permission::CampaignsUpdate, $this->context->organization());
    }

    public function submit(User $user, Campaign $campaign): bool
    {
        if ($user->isPlatformUser()) {
            return false;
        }

        return $campaign->isEditable()
            && $this->actsWithin($user, $campaign)
            && $user->hasPermissionTo(Permission::CampaignsSubmit, $this->context->organization());
    }

    /** Platform only, and never by the person who submitted it (spec §25). */
    public function approve(User $user, Campaign $campaign): bool
    {
        return $user->isPlatformUser()
            && $user->hasPermissionTo(Permission::CampaignsApprove)
            && $campaign->submitted_by !== $user->getKey();
    }

    public function reject(User $user, Campaign $campaign): bool
    {
        return $user->isPlatformUser()
            && $user->hasPermissionTo(Permission::CampaignsReject)
            && $campaign->submitted_by !== $user->getKey();
    }

    public function publish(User $user, Campaign $campaign): bool
    {
        return $user->isPlatformUser() && $user->hasPermissionTo(Permission::CampaignsPublish);
    }

    /**
     * Pausing is available to both sides: a client stopping their own spend
     * should never have to wait for us, and the platform needs it for
     * compliance.
     */
    public function pause(User $user, Campaign $campaign): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::CampaignsPause);
        }

        return $this->actsWithin($user, $campaign)
            && $user->hasPermissionTo(Permission::CampaignsPause, $this->context->organization());
    }

    public function resume(User $user, Campaign $campaign): bool
    {
        return $this->pause($user, $campaign);
    }

    /**
     * A campaign is never deleted. Its ledger entries, invoices and reports
     * point at it (spec §62); archiving is the way out.
     */
    public function delete(User $user, Campaign $campaign): bool
    {
        return false;
    }

    public function archive(User $user, Campaign $campaign): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::CampaignsUpdate);
        }

        return $this->actsWithin($user, $campaign)
            && $user->hasPermissionTo(Permission::CampaignsUpdate, $this->context->organization());
    }

    private function actsWithin(User $user, Campaign $campaign): bool
    {
        $organization = $this->context->organization();

        return $user->tenant_id !== null
            && $user->tenant_id === $campaign->tenant_id
            && $organization !== null
            && $organization->getKey() === $campaign->organization_id
            && $user->belongsToOrganization($organization);
    }
}
