<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Identity\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mandatory two-factor enrolment for administrators (spec §9).
 *
 * This used to send an administrator with a confirmed authenticator straight to
 * the dashboard, on the reasoning that there was nothing left to do here. There
 * is: the recovery codes are shown once, on this screen, and confirming an
 * authenticator redirected back through here — so the one moment they were
 * available was spent on a redirect, and an administrator who later lost their
 * phone had nothing to sign in with.
 */
final class TwoFactorSetupController
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Admin/Security/TwoFactorSetup', [
            'required' => (bool) config('platform.security.admin_requires_two_factor'),
            // A secret exists but no code has confirmed it: enrolment was
            // started and not finished, which is its own screen rather than a
            // reason to start again and invalidate the code they just scanned.
            'enabled' => $user->two_factor_secret !== null,
            'confirmed' => $user->hasTwoFactorEnabled(),
        ]);
    }
}
