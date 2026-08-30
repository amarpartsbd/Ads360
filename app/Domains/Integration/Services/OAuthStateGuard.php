<?php

declare(strict_types=1);

namespace App\Domains\Integration\Services;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Identity\Models\User;
use App\Domains\Integration\Exceptions\InvalidOAuthState;
use App\Domains\Integration\Models\OAuthState;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues and redeems OAuth state tokens (spec §16, §98).
 *
 * Without this check, anyone able to make a signed-in client's browser follow a
 * link could complete *their own* provider authorisation inside the client's
 * organization — the platform would then hold an attacker-controlled connection
 * that looks legitimate.
 *
 * A state is therefore bound to the user, the organization and the provider it
 * was issued for, expires quickly, and can be redeemed exactly once. Redemption
 * is a single conditional UPDATE, so two callbacks racing cannot both win.
 */
final class OAuthStateGuard
{
    /**
     * Long enough to sign in at the provider and grant access; short enough
     * that a link left in a browser history is useless.
     */
    private const LIFETIME_MINUTES = 15;

    /**
     * Issue a state and return the value to put in the authorisation URL.
     *
     * Only its hash is stored, so the row cannot be used to forge a callback.
     *
     * @param  list<string>  $scopes
     */
    public function issue(
        Provider $provider,
        User $user,
        Organization $organization,
        array $scopes = [],
        ?string $redirectTo = null,
        ?Request $request = null,
    ): string {
        $state = Str::random(64);

        OAuthState::query()->create([
            'state_hash' => OAuthState::hash($state),
            'tenant_id' => $organization->tenant_id,
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'provider' => $provider,
            'redirect_to' => $redirectTo,
            'scopes' => $scopes,
            'expires_at' => Carbon::now()->addMinutes(self::LIFETIME_MINUTES),
            'ip_address' => $request?->ip(),
        ]);

        return $state;
    }

    /**
     * Redeem a state, or refuse the callback.
     *
     * Every check that follows is a way the callback could be someone else's;
     * all of them must pass.
     *
     * @throws InvalidOAuthState
     */
    public function redeem(
        string $state,
        Provider $provider,
        User $user,
        Organization $organization,
    ): OAuthState {
        return DB::transaction(function () use ($state, $provider, $user, $organization): OAuthState {
            /** @var OAuthState|null $record */
            $record = OAuthState::query()
                ->where('state_hash', OAuthState::hash($state))
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                throw InvalidOAuthState::unknown();
            }

            if ($record->consumed_at !== null) {
                throw InvalidOAuthState::alreadyUsed();
            }

            if ($record->hasExpired()) {
                throw InvalidOAuthState::expired();
            }

            // The person finishing must be the person who started.
            if ($record->user_id !== $user->getKey()) {
                throw InvalidOAuthState::wrongUser();
            }

            // And they must still be working in the same organization, so a
            // connection cannot be landed in a workspace they switched away
            // from mid-flow.
            if ($record->organization_id !== $organization->getKey()) {
                throw InvalidOAuthState::wrongOrganization();
            }

            if ($record->provider !== $provider) {
                throw InvalidOAuthState::providerMismatch();
            }

            // Conditional update: whichever transaction gets here first marks
            // it consumed, and the second finds zero rows affected.
            $claimed = OAuthState::query()
                ->whereKey($record->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => Carbon::now()]);

            if ($claimed === 0) {
                throw InvalidOAuthState::alreadyUsed();
            }

            return $record->refresh();
        });
    }

    /**
     * Remove states that were never completed.
     *
     * Consumed rows are kept for a while: they are the evidence that a given
     * connection was authorised by a given person at a given moment.
     */
    public function pruneExpired(): int
    {
        return OAuthState::query()
            ->where('expires_at', '<', Carbon::now()->subDay())
            ->delete();
    }
}
