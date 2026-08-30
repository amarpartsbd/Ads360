<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\InvitationStatus;
use App\Domains\Tenant\Enums\MembershipStatus;
use App\Domains\Tenant\Models\OrganizationInvitation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Redeems an invitation (spec §82).
 *
 * Accepting either creates the account or attaches an existing one, then makes
 * the membership and the role grant in a single transaction, so a half-joined
 * user cannot exist.
 *
 * The invitation is re-validated at redemption: the state at the moment the
 * email was sent is not evidence of the state now.
 */
final class AcceptInvitation
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array{name?: string, password?: string}  $attributes
     *
     * @throws ValidationException
     */
    public function handle(string $token, array $attributes = [], ?User $authenticated = null): User
    {
        return DB::transaction(function () use ($token, $attributes, $authenticated): User {
            /** @var OrganizationInvitation|null $invitation */
            $invitation = OrganizationInvitation::forToken($token)->lockForUpdate()->first();

            if ($invitation === null) {
                throw ValidationException::withMessages([
                    'token' => 'This invitation is no longer valid. Ask for a new one.',
                ]);
            }

            $organization = $invitation->organization()->withoutGlobalScopes()->firstOrFail();
            $role = $invitation->role()->firstOrFail();

            $user = $authenticated ?? $this->findExistingUser($invitation->email);

            $user = $user === null
                ? $this->createUser($invitation, $attributes)
                : $this->assertUserMayAccept($user, $invitation);

            // Attaching membership and the role together means the new member
            // is never briefly present with no permissions, or vice versa.
            $organization->members()->syncWithoutDetaching([
                $user->getKey() => [
                    'tenant_id' => $organization->tenant_id,
                    'status' => MembershipStatus::Active->value,
                    'is_primary' => false,
                    'invited_by' => $invitation->invited_by,
                    'invited_at' => $invitation->created_at,
                    'joined_at' => Carbon::now(),
                ],
            ]);

            $alreadyGranted = $user->roles()
                ->wherePivot('role_id', $role->getKey())
                ->wherePivot('organization_id', $organization->getKey())
                ->exists();

            if (! $alreadyGranted) {
                $user->roles()->attach($role->getKey(), [
                    'organization_id' => $organization->getKey(),
                    'tenant_id' => $organization->tenant_id,
                    'granted_by' => $invitation->invited_by,
                ]);
            }

            $user->forgetCachedPermissions();

            $invitation->forceFill([
                'status' => InvitationStatus::Accepted,
                'accepted_at' => Carbon::now(),
                'accepted_by' => $user->getKey(),
                // The token is spent. Clearing the hash makes a replay
                // impossible even if the row is later reopened by a bug.
                'token_hash' => hash('sha256', Str::uuid()->toString()),
            ])->save();

            $this->audit->record(
                action: AuditAction::InvitationAccepted,
                resource: $invitation,
                after: ['role' => $role->slug],
                organization: $organization,
                actor: $user,
            );

            return $user;
        });
    }

    private function findExistingUser(string $email): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [Str::lower($email)])->first();
    }

    /**
     * @param  array{name?: string, password?: string}  $attributes
     */
    private function createUser(OrganizationInvitation $invitation, array $attributes): User
    {
        if (! isset($attributes['password'])) {
            throw ValidationException::withMessages([
                'password' => 'Choose a password to finish setting up your account.',
            ]);
        }

        $user = new User([
            'name' => $attributes['name'] ?? $invitation->name ?? Str::before($invitation->email, '@'),
            'email' => $invitation->email,
            'password' => $attributes['password'],
            'status' => UserStatus::Active,
        ]);

        $user->tenant_id = $invitation->tenant_id;
        $user->is_platform_user = false;
        // The address is proven by receipt of the invitation, so a second
        // verification round would ask them to confirm what they just used.
        $user->email_verified_at = Carbon::now();
        $user->terms_accepted_at = Carbon::now();
        $user->save();

        return $user;
    }

    /**
     * An already-registered user may only accept an invitation addressed to
     * them, and only within their own tenant. Both checks matter: without the
     * first, a forwarded link joins the wrong person; without the second, a
     * user could gain a foothold in another tenant.
     */
    private function assertUserMayAccept(User $user, OrganizationInvitation $invitation): User
    {
        if (Str::lower($user->email) !== Str::lower($invitation->email)) {
            throw ValidationException::withMessages([
                'token' => 'This invitation was sent to a different email address.',
            ]);
        }

        if ($user->is_platform_user) {
            throw ValidationException::withMessages([
                'token' => 'Platform accounts cannot join a client organization.',
            ]);
        }

        if ($user->tenant_id !== null && $user->tenant_id !== $invitation->tenant_id) {
            throw ValidationException::withMessages([
                'token' => 'This account already belongs to a different workspace.',
            ]);
        }

        if ($user->tenant_id === null) {
            $user->forceFill(['tenant_id' => $invitation->tenant_id])->save();
        }

        return $user;
    }
}
