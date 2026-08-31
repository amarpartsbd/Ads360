<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Switches which organization the user is working in.
 *
 * The submitted identifier is a claim, not context: it is looked up strictly
 * within the organizations this user can reach — their own memberships, or the
 * clients of their own agency (spec §5, §42) — so naming another tenant's
 * organization finds nothing.
 */
final class OrganizationSwitchController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization' => ['required', 'string', 'size:26'],
        ]);

        /** @var User $user */
        $user = $request->user();

        /** @var Organization|null $organization */
        $organization = $user->reachableOrganizations()
            ->where('organizations.public_id', $validated['organization'])
            ->first();

        if ($organization === null) {
            throw ValidationException::withMessages([
                'organization' => 'You do not have access to that organization.',
            ]);
        }

        $request->session()->put(ResolveTenantContext::SESSION_KEY, $organization->getKey());

        return back()->with('success', "Switched to {$organization->name}.");
    }
}
