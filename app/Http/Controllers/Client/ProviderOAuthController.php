<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Services\ProviderManager;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Integration\Actions\CompleteProviderConnection;
use App\Domains\Integration\Exceptions\InvalidOAuthState;
use App\Domains\Integration\Models\ProviderConnection;
use App\Domains\Integration\Services\OAuthStateGuard;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * The authorisation round trip (spec §16).
 *
 * The two halves are deliberately asymmetric. Starting a flow is cheap and
 * needs only a permission check. Finishing one accepts a value from an
 * untrusted redirect, so every field in it is re-derived server-side: the
 * organization comes from the resolved context, the user from the session, and
 * the state is redeemed against a stored hash that pins both (Rule 7).
 *
 * The authorisation code itself is never logged or audited — it is a bearer
 * credential for the length of its life (Rule 12).
 */
final class ProviderOAuthController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ProviderManager $providers,
        private readonly OAuthStateGuard $states,
        private readonly AuditRecorder $audit,
    ) {}

    /** Sends the client to the provider's consent screen. */
    public function start(Request $request, string $provider): SymfonyRedirect|RedirectResponse
    {
        Gate::authorize('create', ProviderConnection::class);

        $target = $this->resolveProvider($provider);

        if ($target === null || ! $this->providers->isAvailable($target)) {
            return redirect()
                ->route('client.assets.index')
                ->with('error', 'That advertising platform is not available yet.');
        }

        $organization = $this->context->requireOrganization();
        $adapter = $this->providers->for($target);

        $state = $this->states->issue(
            provider: $target,
            user: $request->user(),
            organization: $organization,
            request: $request,
        );

        try {
            $authorization = $adapter->authorizationRequest($state);
        } catch (ProviderUnavailable $exception) {
            return redirect()
                ->route('client.assets.index')
                ->with('error', $exception->clientMessage);
        }

        // An external redirect, so `away()` rather than a named route.
        return redirect()->away($authorization->url);
    }

    /** Where the provider sends the client back. */
    public function callback(
        Request $request,
        string $provider,
        CompleteProviderConnection $complete,
    ): RedirectResponse {
        Gate::authorize('create', ProviderConnection::class);

        $target = $this->resolveProvider($provider);

        if ($target === null) {
            return $this->refuse('That advertising platform is not available yet.');
        }

        // The provider reports a refusal in the query string. A client who
        // changed their mind is not an error worth a stack trace.
        if ($request->filled('error')) {
            return $this->refuse('The connection was not completed. You can try again whenever you are ready.');
        }

        $code = $request->string('code')->toString();
        $state = $request->string('state')->toString();

        if ($code === '' || $state === '') {
            return $this->refuse('That connection link is incomplete. Please start again.');
        }

        $organization = $this->context->requireOrganization();

        try {
            $this->states->redeem($state, $target, $request->user(), $organization);
        } catch (InvalidOAuthState $exception) {
            // Recorded because a rejected state is a security signal, not a
            // user mistake — and the state value itself is not stored.
            $this->audit->record(
                action: AuditAction::OAuthStateRejected,
                context: ['provider' => $target->value, 'reason' => $exception->getMessage()],
                organization: $organization,
                actor: $request->user(),
            );

            return $this->refuse(InvalidOAuthState::CLIENT_MESSAGE);
        }

        try {
            $connection = $complete->handle($target, $code, $organization, $request->user());
        } catch (ProviderUnavailable $exception) {
            return $this->refuse($exception->clientMessage);
        }

        return redirect()
            ->route('client.assets.index')
            ->with('success', "Your {$connection->provider->connectionLabel()} account is connected.");
    }

    private function resolveProvider(string $provider): ?Provider
    {
        return Provider::tryFrom(strtoupper($provider));
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('client.assets.index')->with('error', $message);
    }
}
