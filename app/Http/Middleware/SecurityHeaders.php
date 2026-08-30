<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response hardening (spec §53).
 *
 * HSTS is only emitted over HTTPS, because sending it on a plain-HTTP response
 * is meaningless and sending it in local development would pin developers to a
 * scheme their machine does not serve.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set(
            'Permissions-Policy',
            'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()'
        );

        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        if ($policy = $this->contentSecurityPolicy()) {
            $response->headers->set('Content-Security-Policy', $policy);
        }

        return $response;
    }

    /**
     * The dev server needs eval and websocket access for hot module
     * replacement, so the policy is only enforced outside local development.
     */
    private function contentSecurityPolicy(): ?string
    {
        if (! config('platform.security.content_security_policy')) {
            return null;
        }

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "object-src 'none'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            // Inertia serialises page props into the initial document, so the
            // style and script hosts stay same-origin with no inline scripts.
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "connect-src 'self'",
        ]);
    }
}
