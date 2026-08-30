<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mandatory two-factor enrolment for administrators (spec §9).
 *
 * An administrator who already has a confirmed authenticator is sent on to the
 * dashboard; there is nothing to do here.
 */
final class TwoFactorSetupController
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('admin.dashboard');
        }

        return Inertia::render('Admin/Security/TwoFactorSetup', [
            'required' => (bool) config('platform.security.admin_requires_two_factor'),
        ]);
    }
}
