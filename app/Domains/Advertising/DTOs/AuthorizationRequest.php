<?php

declare(strict_types=1);

namespace App\Domains\Advertising\DTOs;

/**
 * Where to send a client to authorise the platform, and the state that must
 * come back with them (spec §16).
 */
final readonly class AuthorizationRequest
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $url,
        public string $state,
        public array $scopes = [],
    ) {}
}
