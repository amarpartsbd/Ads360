<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\TeamInvitation;
use App\Domains\Tenant\Enums\InvitationStatus;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\OrganizationInvitation;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invites someone to join an organization (spec §82).
 *
 * Two rules do the security work here. The role must be one the inviter is
 * themselves allowed to grant, so an invitation cannot be used to hand out
 * access the inviter does not have. And the token is generated once, mailed,
 * and stored only as a hash — the database never holds anything redeemable.
 */
final class InviteTeamMember
{
    /** Long enough to act on, short enough that a forgotten mailbox is not a standing key. */
    public const EXPIRY_DAYS = 7;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        Organization $organization,
        User $inviter,
        string $email,
        Role $role,
        ?string $name = null,
    ): OrganizationInvitation {
        $email = Str::lower(trim($email));

        $this->guardAgainstEscalation($inviter, $role);
        $this->guardAgainstDuplicates($organization, $email);

        [$invitation, $token] = DB::transaction(function () use ($organization, $inviter, $email, $role, $name): array {
            $token = Str::random(64);

            $invitation = new OrganizationInvitation([
                'organization_id' => $organization->getKey(),
                'email' => $email,
                'name' => $name,
                'role_id' => $role->getKey(),
                'token_hash' => OrganizationInvitation::hashToken($token),
                'status' => InvitationStatus::Pending,
                'invited_by' => $inviter->getKey(),
                'expires_at' => Carbon::now()->addDays(self::EXPIRY_DAYS),
                'last_sent_at' => Carbon::now(),
            ]);
            $invitation->tenant_id = $organization->tenant_id;
            $invitation->save();

            $this->audit->record(
                action: AuditAction::InvitationSent,
                resource: $invitation,
                after: ['email' => $email, 'role' => $role->slug],
                organization: $organization,
                actor: $inviter,
            );

            return [$invitation, $token];
        });

        // Sent outside the transaction: a mail failure must not roll back a
        // stored invitation, and the invitation can be resent.
        Notification::route('mail', $email)->notify(
            new TeamInvitation($organization, $role, $inviter, $token, $invitation->expires_at)
        );

        return $invitation;
    }

    /**
     * Resends an existing invitation with a fresh token, invalidating the old
     * one so a forwarded email cannot be redeemed after a resend.
     */
    public function resend(OrganizationInvitation $invitation, User $actor): OrganizationInvitation
    {
        if (! $invitation->isRedeemable()) {
            throw ValidationException::withMessages([
                'invitation' => 'That invitation is no longer active. Send a new one instead.',
            ]);
        }

        $token = Str::random(64);

        $invitation->forceFill([
            'token_hash' => OrganizationInvitation::hashToken($token),
            'expires_at' => Carbon::now()->addDays(self::EXPIRY_DAYS),
            'last_sent_at' => Carbon::now(),
        ])->save();

        $organization = $invitation->organization()->firstOrFail();
        $role = $invitation->role()->firstOrFail();

        $this->audit->record(
            action: AuditAction::InvitationResent,
            resource: $invitation,
            context: ['email' => $invitation->email],
            organization: $organization,
            actor: $actor,
        );

        Notification::route('mail', $invitation->email)->notify(
            new TeamInvitation($organization, $role, $actor, $token, $invitation->expires_at)
        );

        return $invitation;
    }

    /**
     * Nobody may grant a role carrying permissions they do not themselves hold.
     * Without this, a client admin could invite someone as owner and then sign
     * in as them.
     */
    private function guardAgainstEscalation(User $inviter, Role $role): void
    {
        if (! $inviter->can('assign', $role)) {
            throw ValidationException::withMessages([
                'role' => 'You cannot invite someone with that role.',
            ]);
        }

        $granted = $inviter->permissionsFor($this->context->organization());
        $requested = $role->permissions()->pluck('name');

        $excess = $requested->diff($granted);

        if ($excess->isNotEmpty()) {
            throw ValidationException::withMessages([
                'role' => 'That role includes permissions your own account does not have.',
            ]);
        }
    }

    private function guardAgainstDuplicates(Organization $organization, string $email): void
    {
        $alreadyMember = $organization->members()
            ->whereRaw('lower(users.email) = ?', [$email])
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages([
                'email' => 'That person is already a member of this organization.',
            ]);
        }

        $alreadyInvited = OrganizationInvitation::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', InvitationStatus::Pending)
            ->whereRaw('lower(email) = ?', [$email])
            ->exists();

        if ($alreadyInvited) {
            throw ValidationException::withMessages([
                'email' => 'An invitation is already pending for that address. Resend it instead.',
            ]);
        }
    }
}
