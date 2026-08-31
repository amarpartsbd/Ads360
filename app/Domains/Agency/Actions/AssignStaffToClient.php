<?php

declare(strict_types=1);

namespace App\Domains\Agency\Actions;

use App\Domains\Agency\Exceptions\AgencyException;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Enums\RoleScope;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\MembershipStatus;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Puts an agency staff member on one of the agency's clients (spec §42).
 *
 * The two agency role scopes do different jobs, and this action is the second
 * one:
 *
 *   - **agency-owner and agency-admin** are TENANT-scoped. They reach every
 *     client, including ones created after they joined. Nothing is assigned.
 *   - **agency-manager and agency-staff** are ORGANIZATION-scoped. They reach
 *     exactly the clients they have been put on, one row at a time. That is
 *     what "prevent agency users from accessing other agencies" means in
 *     practice at the level below the tenant boundary: a media buyer working on
 *     one client cannot read another client's spend.
 *
 * Both the membership and the role grant are written, in one transaction. A
 * membership without a grant is someone who can open a workspace and do nothing
 * in it; a grant without a membership is a permission with no way to reach the
 * organization it applies to.
 */
final class AssignStaffToClient
{
    /**
     * The roles an agency may put on one of its clients. Deliberately not the
     * tenant-wide ones: assigning an agency-owner to a single client would
     * read as narrowing their access while actually widening it, because the
     * role's own scope already spans the agency.
     *
     * @var list<string>
     */
    public const ASSIGNABLE_ROLES = ['agency-manager', 'agency-staff'];

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @throws AgencyException
     */
    public function handle(
        Tenant $agency,
        Organization $client,
        User $staff,
        string $roleSlug,
        ?User $actor = null,
    ): void {
        $this->assertClientOfAgency($agency, $client);
        $this->assertStaffOfAgency($agency, $staff);

        if (! in_array($roleSlug, self::ASSIGNABLE_ROLES, true)) {
            throw new AgencyException(
                "[{$roleSlug}] is not a role an agency assigns to a client. "
                .'Use one of: '.implode(', ', self::ASSIGNABLE_ROLES).'.'
            );
        }

        $role = Role::query()
            ->whereNull('tenant_id')
            ->where('slug', $roleSlug)
            ->where('scope', RoleScope::Organization->value)
            ->first();

        if ($role === null) {
            throw AgencyException::staffRoleUnavailable($roleSlug);
        }

        DB::transaction(function () use ($agency, $client, $staff, $role): void {
            $membership = $staff->membershipIn($client);

            if ($membership === null) {
                /*
                 * `syncWithoutDetaching` rather than `attach`: a staff member
                 * previously suspended on this client still has a row, and a
                 * second attach would violate the membership unique index.
                 */
                $client->members()->syncWithoutDetaching([
                    $staff->getKey() => [
                        'tenant_id' => $agency->getKey(),
                        'status' => MembershipStatus::Active->value,
                        'is_primary' => false,
                        'joined_at' => Carbon::now(),
                    ],
                ]);
            }

            $staff->roles()->syncWithoutDetaching([
                $role->getKey() => [
                    'organization_id' => $client->getKey(),
                    'tenant_id' => $agency->getKey(),
                ],
            ]);

            $staff->forgetCachedPermissions();
        });

        $this->audit->record(
            action: AuditAction::AgencyStaffAssigned,
            resource: $staff,
            after: ['client' => $client->name, 'role' => $roleSlug],
            organization: $client,
            actor: $actor,
        );
    }

    /**
     * Removes a staff member from one client, leaving every other assignment
     * they hold untouched.
     *
     * The membership is revoked rather than deleted: it is the record that
     * this person could see this client's campaigns during a period, and audit
     * entries written in that time point at it (§62).
     *
     * @throws AgencyException
     */
    public function remove(Tenant $agency, Organization $client, User $staff, ?User $actor = null): void
    {
        $this->assertClientOfAgency($agency, $client);
        $this->assertStaffOfAgency($agency, $staff);

        DB::transaction(function () use ($client, $staff): void {
            $client->members()->updateExistingPivot($staff->getKey(), [
                'status' => MembershipStatus::Revoked->value,
            ]);

            $assignable = Role::query()
                ->whereNull('tenant_id')
                ->whereIn('slug', self::ASSIGNABLE_ROLES)
                ->pluck('id');

            /*
             * Only the grants naming *this* client. Detaching by role alone
             * would silently strip the same person's access to every other
             * client they work on.
             */
            $staff->roles()
                ->wherePivot('organization_id', $client->getKey())
                ->detach($assignable->all());

            $staff->forgetCachedPermissions();
        });

        $this->audit->record(
            action: AuditAction::AgencyStaffUnassigned,
            resource: $staff,
            after: ['client' => $client->name],
            organization: $client,
            actor: $actor,
        );
    }

    /**
     * @throws AgencyException
     */
    private function assertClientOfAgency(Tenant $agency, Organization $client): void
    {
        if (! config('platform.features.agency_module')) {
            throw AgencyException::moduleDisabled();
        }

        if (! $agency->type->managesClients()) {
            throw AgencyException::notAnAgency($agency);
        }

        // The tenant boundary, checked here as well as by the global scope.
        if ($client->tenant_id !== $agency->getKey()) {
            throw AgencyException::notYourClient();
        }

        if ($client->isHouseAccount()) {
            throw AgencyException::houseAccountIsNotAClient();
        }
    }

    /**
     * @throws AgencyException
     */
    private function assertStaffOfAgency(Tenant $agency, User $staff): void
    {
        if ($staff->tenant_id !== $agency->getKey() || $staff->isPlatformUser()) {
            throw AgencyException::notYourStaff();
        }
    }
}
