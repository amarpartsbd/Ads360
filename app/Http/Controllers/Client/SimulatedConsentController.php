<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domains\Advertising\Enums\Provider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stands in for a provider's consent screen during development (spec §95).
 *
 * The mock adapters point their authorisation URL here, so the whole round
 * trip — issue a state, leave the application, come back with a code — can be
 * walked through before any provider has approved an app review.
 *
 * It refuses to run in production. A live environment that could mint its own
 * authorisation codes would make the callback's checks meaningless.
 */
final class SimulatedConsentController
{
    public function show(Request $request, string $provider): Response
    {
        $this->assertNotProduction();

        $target = Provider::tryFrom(strtoupper($provider));

        abort_if($target === null, 404);

        return Inertia::render('Client/Assets/SimulatedConsent', [
            'provider' => [
                'value' => $target->value,
                'label' => $target->connectionLabel(),
            ],
            'state' => $request->string('state')->toString(),
        ]);
    }

    /** Grants or refuses, and returns to the real callback either way. */
    public function submit(Request $request, string $provider): RedirectResponse
    {
        $this->assertNotProduction();

        $target = Provider::tryFrom(strtoupper($provider));

        abort_if($target === null, 404);

        $validated = $request->validate([
            'state' => ['required', 'string', 'max:255'],
            'decision' => ['required', 'string', 'in:grant,refuse'],
        ]);

        $query = $validated['decision'] === 'grant'
            ? ['code' => 'mock-code-'.Str::random(32), 'state' => $validated['state']]
            : ['error' => 'access_denied', 'state' => $validated['state']];

        return redirect()->route('client.assets.oauth.callback', [
            'provider' => Str::lower($target->value),
            ...$query,
        ]);
    }

    private function assertNotProduction(): void
    {
        abort_if(app()->isProduction(), 404);
    }
}
