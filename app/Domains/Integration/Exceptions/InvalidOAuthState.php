<?php

declare(strict_types=1);

namespace App\Domains\Integration\Exceptions;

use RuntimeException;

/**
 * An OAuth callback arrived without a state this platform can vouch for
 * (spec §16, §98).
 *
 * Every case looks the same to the client: the connection did not complete,
 * start again. The distinctions exist so the log records which check failed —
 * a forged state and an expired one mean very different things operationally.
 */
final class InvalidOAuthState extends RuntimeException
{
    public const CLIENT_MESSAGE =
        'We could not complete that connection. Please start again from your advertising assets.';

    public static function unknown(): self
    {
        return new self('The callback carried a state this platform did not issue.');
    }

    public static function alreadyUsed(): self
    {
        return new self('The callback carried a state that has already been redeemed.');
    }

    public static function expired(): self
    {
        return new self('The callback carried an expired state.');
    }

    public static function wrongUser(): self
    {
        return new self('The callback was completed by a different user than the one who started it.');
    }

    public static function wrongOrganization(): self
    {
        return new self('The callback was completed in a different organization than the one it was issued for.');
    }

    public static function providerMismatch(): self
    {
        return new self('The callback arrived on a different provider than the state was issued for.');
    }
}
