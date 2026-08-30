<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shared;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Support\HomeRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WelcomeController
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            return redirect(HomeRedirect::for($user));
        }

        return Inertia::render('Welcome', [
            'platformName' => config('platform.name'),
        ]);
    }
}
