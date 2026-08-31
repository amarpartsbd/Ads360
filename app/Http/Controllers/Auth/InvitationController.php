<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domains\Identity\Actions\AcceptInvitation;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Rules\PasswordRules;
use App\Domains\Identity\Support\HomeRedirect;
use App\Domains\Tenant\Models\OrganizationInvitation;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accepting a team invitation (spec §82).
 *
 * The token in the URL is the credential. It is looked up by hash, never
 * echoed back into a response beyond the form that posts it, and it is spent on
 * acceptance.
 */
final class InvitationController
{
    use PasswordRules;

    public function show(Request $request, string $token): Response
    {
        /** @var OrganizationInvitation|null $invitation */
        $invitation = OrganizationInvitation::forToken($token)
            ->with(['organization:id,public_id,name', 'role:id,name'])
            ->first();

        if ($invitation === null) {
            return Inertia::render('Auth/InvitationInvalid');
        }

        $existingUser = User::query()
            ->whereRaw('lower(email) = ?', [Str::lower($invitation->email)])
            ->exists();

        return Inertia::render('Auth/AcceptInvitation', [
            'token' => $token,
            'organizationName' => $invitation->organization->name,
            'roleName' => $invitation->role->name,
            'email' => $invitation->email,
            'suggestedName' => $invitation->name,
            'expiresAt' => $invitation->expires_at->toIso8601String(),
            // Decides whether the form asks for a password or just a
            // confirmation that they want to join.
            'hasAccount' => $existingUser,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $authenticated = $request->user();

        $rules = ['name' => ['nullable', 'string', 'max:255']];

        // A signed-in user does not set a password; a new one must.
        if (! $authenticated instanceof User) {
            $needsPassword = User::query()
                ->whereRaw('lower(email) = ?', [
                    Str::lower((string) OrganizationInvitation::forToken($token)->value('email')),
                ])
                ->doesntExist();

            if ($needsPassword) {
                $rules['password'] = $this->passwordRules();
            }
        }

        $validated = $request->validate($rules);

        $user = app(AcceptInvitation::class)->handle($token, $validated, $authenticated);

        if (! $authenticated instanceof User) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        // The new membership becomes the working organization straight away, so
        // the person lands somewhere useful rather than in whatever workspace
        // they happened to have selected before.
        $request->session()->forget(ResolveTenantContext::SESSION_KEY);

        return redirect(HomeRedirect::for($user))
            ->with('success', 'You have joined the organization.');
    }
}
