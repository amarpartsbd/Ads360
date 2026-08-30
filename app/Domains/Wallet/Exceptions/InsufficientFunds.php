<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Exceptions;

use App\Support\Values\Money;
use DomainException;

/**
 * A wallet was asked to give up more than it holds (spec §31).
 *
 * The message is safe to show a client: it names their own balance and what
 * they tried to spend, nothing else.
 */
final class InsufficientFunds extends DomainException
{
    public function __construct(
        public readonly Money $requested,
        public readonly Money $available,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forDebit(Money $requested, Money $available): self
    {
        return new self(
            $requested,
            $available,
            "This would spend {$requested->format()} but only {$available->format()} is available.",
        );
    }

    public static function forReservation(Money $requested, Money $available): self
    {
        return new self(
            $requested,
            $available,
            "Reserving {$requested->format()} needs more than the {$available->format()} available.",
        );
    }
}
