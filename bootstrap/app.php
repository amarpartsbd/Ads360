<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceAdminTwoFactor;
use App\Http\Middleware\EnsurePlatformUser;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Registered separately so provider webhooks never pick up session
        // handling or CSRF: they authenticate by signature, not by cookie.
        then: function (): void {
            Route::prefix('webhooks')
                ->middleware([AssignRequestId::class, SecurityHeaders::class])
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies();

        $middleware->web(append: [
            AssignRequestId::class,
            SecurityHeaders::class,
            // Tenant context is resolved once, immediately after authentication
            // state is known, and everything downstream reads it from there.
            ResolveTenantContext::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'tenant' => EnsureTenantContext::class,
            'platform' => EnsurePlatformUser::class,
            'admin.2fa' => EnforceAdminTwoFactor::class,
        ]);

        // Inertia expects a redirect, not a 302-to-JSON, when the session dies.
        $middleware->redirectGuestsTo(fn (): string => route('login'));

        // The other door into the same mistake: an account that is already
        // signed in and asks for the login page is sent home, and home is not
        // the same place for platform staff as for a client.
        $middleware->redirectUsersTo(function (Request $request): string {
            $user = $request->user();

            return $user instanceof User ? $user->homeRoute() : route('client.dashboard');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Technical detail stays in the logs; the interface gets a page it can
        // render (spec §80).
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($request->expectsJson() || ! app()->environment('production')) {
                return $response;
            }

            if (in_array($response->getStatusCode(), [403, 404, 419, 429, 500, 503], true)) {
                return inertia('Error', [
                    'status' => $response->getStatusCode(),
                ])
                    ->toResponse($request)
                    ->setStatusCode($response->getStatusCode());
            }

            return $response;
        });
    })->create();
