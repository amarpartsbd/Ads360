<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use App\Support\Values\Currency;
use DomainException;

/**
 * Raised when two monetary amounts in different currencies are combined.
 *
 * Currency conversion is never implicit: it must go through the exchange-rate
 * engine so the rate used is recorded with the transaction (spec §35).
 */
final class CurrencyMismatch extends DomainException
{
    public static function between(Currency $left, Currency $right): self
    {
        return new self(
            "Cannot operate on amounts in different currencies [{$left->code}] and [{$right->code}]."
        );
    }
}
