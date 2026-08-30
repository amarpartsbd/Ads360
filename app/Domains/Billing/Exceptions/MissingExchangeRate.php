<?php

declare(strict_types=1);

namespace App\Domains\Billing\Exceptions;

use DomainException;

/**
 * No rate covers a conversion the platform was asked to make (spec §35).
 *
 * Deliberately fatal rather than falling back to a guess: converting money at
 * an assumed rate is worse than refusing to convert it.
 */
final class MissingExchangeRate extends DomainException
{
    public static function forPair(string $base, string $quote, ?string $at = null): self
    {
        $when = $at === null ? 'now' : "at {$at}";

        return new self("No exchange rate is configured for {$base} to {$quote} {$when}.");
    }
}
