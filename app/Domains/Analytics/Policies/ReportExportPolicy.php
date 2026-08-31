<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Policies;

use App\Domains\Analytics\Models\ReportExport;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Services\TenantContext;

/**
 * Authorization for report exports (spec §39, §68).
 *
 * An export is a file of one organization's spend and conversions. The
 * membership check is what stops it being anyone else's.
 */
final class ReportExportPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::ReportsView);
        }

        return $user->hasPermissionTo(Permission::ReportsView, $this->context->organization());
    }

    public function view(User $user, ReportExport $export): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::ReportsView);
        }

        return $this->actsWithin($user, $export)
            && $user->hasPermissionTo(Permission::ReportsView, $this->context->organization());
    }

    public function create(User $user): bool
    {
        if ($user->isPlatformUser()) {
            return $user->hasPermissionTo(Permission::ReportsExport);
        }

        return $user->hasPermissionTo(Permission::ReportsExport, $this->context->organization());
    }

    /** Downloading is separate from viewing the list, and separately audited. */
    public function download(User $user, ReportExport $export): bool
    {
        return $this->view($user, $export)
            && ($user->isPlatformUser()
                || $user->hasPermissionTo(Permission::ReportsExport, $this->context->organization()));
    }

    /**
     * Files are removed by the expiry sweep, not by hand. A client deleting
     * one would leave the record pointing at nothing while the audit trail
     * still says it was generated.
     */
    public function delete(User $user, ReportExport $export): bool
    {
        return false;
    }

    private function actsWithin(User $user, ReportExport $export): bool
    {
        $organization = $this->context->organization();

        return $user->tenant_id !== null
            && $user->tenant_id === $export->tenant_id
            && $organization !== null
            && $organization->getKey() === $export->organization_id
            && $user->belongsToOrganization($organization);
    }
}
