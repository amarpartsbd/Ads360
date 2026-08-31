<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Domains\Identity\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where a sign-in lands (spec §8, §9).
 *
 * Fortify redirects everyone to one configured address. This platform has two
 * front doors and no account may use both, so it asks the account which one is
 * theirs. `intended()` still wins when the visitor was on their way somewhere
 * specific — a deep link they followed before signing in should survive the
 * detour through the login form.
 */
final class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
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
