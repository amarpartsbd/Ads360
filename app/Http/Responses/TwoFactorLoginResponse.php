<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Domains\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where a sign-in lands after the second factor.
 *
 * The same decision as LoginResponse, and it has to be made twice because
 * Fortify completes the two flows through different responses. Administrators
 * are required to hold an authenticator (spec §9), so this is the one they
 * actually arrive through — getting it right only in LoginResponse would fix
 * the case that does not apply to them.
 */
final class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        return redirect()->intended($this->home($request));
    }

    private function home(Request $request): string
    {
        $user = $request->user();

        return $user instanceof User
            ? $user->homeRoute()
            : Fortify::redirects('login');
    }
}
