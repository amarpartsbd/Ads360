<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation id (spec §57).
 *
 * The id is attached to the request, shared with the log context so every line
 * written while handling the request carries it, and returned as a response
 * header so a client can quote it in a support ticket.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        // An inbound id is accepted only if it looks like one, so a caller
        // cannot inject arbitrary text into log lines.
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $requestId = preg_match('/^[A-Za-z0-9_.-]{8,64}$/', $incoming) === 1
            ? $incoming
            : (string) Str::ulid();

        $request->attributes->set('request_id', $requestId);
        Log::shareContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
