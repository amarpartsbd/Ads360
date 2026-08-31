<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Exceptions;

use RuntimeException;

/**
 * No managed ad account could be given to a campaign (spec §19).
 *
 * This is an operational problem, not a client mistake, and the two messages
 * reflect that. The client is told plainly that we are arranging an account;
 * the reasons — which pools were considered and why each account was skipped —
 * are for the operator who has to fix it.
 */
final class AllocationFailed extends RuntimeException
{
    public const CLIENT_MESSAGE =
        'We could not assign an advertising account to this campaign yet. '
        .'Our team has been notified and will sort it out shortly.';

    /**
     * @param  list<string>  $reasons
     */
    private function __construct(public readonly array $reasons, string $message)
    {
        parent::__construct($message);
    }

    public static function noPool(): self
    {
        return new self(
            ['No active pool matches this campaign\'s provider and currency.'],
            'No active ad account pool matches the campaign.',
        );
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function noEligibleAccount(array $reasons): self
    {
        return new self(
            $reasons,
            'No ad account in any matching pool is eligible: '.implode(' ', $reasons),
        );
    }

    public function clientMessage(): string
    {
        return self::CLIENT_MESSAGE;
    }
}
