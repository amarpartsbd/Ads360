<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
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
        /*
         * Generated before the response is built, because the view needs it.
         *
         * `script-src 'self'` refuses every inline script, and the page has one
         * it cannot do without: Ziggy writes the route table into the document
         * as an inline `<script>`, and the front end cannot build a single URL
         * without it. Blocked, React never mounts and the browser shows a blank
         * page with the reason only in its console — which is what this policy
         * did on the first deployment that had it switched on, because it is
         * off by default wherever APP_DEBUG is true.
         *
         * A nonce is the fix rather than `'unsafe-inline'`: it admits the one
         * script this application actually emits, and keeps refusing every
         * script an attacker manages to inject into the page.
         */
        $nonce = base64_encode(random_bytes(16));
        View::share('cspNonce', $nonce);

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

        if ($policy = $this->contentSecurityPolicy($nonce)) {
            $response->headers->set('Content-Security-Policy', $policy);
        }

        return $response;
    }

    /**
     * The dev server needs eval and websocket access for hot module
     * replacement, so the policy is only enforced outside local development.
     */
    private function contentSecurityPolicy(string $nonce): ?string
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
            /*
             * Same-origin scripts, plus the one inline script this request
             * emits. Naming it by nonce rather than allowing inline wholesale
             * is the difference between permitting a script we wrote and
             * permitting any script that reaches the page.
             */
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "connect-src 'self'",
        ]);
    }
}
