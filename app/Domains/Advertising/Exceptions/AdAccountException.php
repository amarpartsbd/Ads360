<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Exceptions;

use App\Domains\Advertising\Enums\AdAccountStatus;
use RuntimeException;

/**
 * A refusal from the ad account inventory that the interface can show as-is
 * (spec §80): plain language, no provider error codes, no internal identifiers.
 */
final class AdAccountException extends RuntimeException
{
    public static function invalidTransition(AdAccountStatus $from, AdAccountStatus $to): self
    {
        return new self(sprintf(
            'An account that is %s cannot be moved to %s.',
            strtolower($from->label()),
            strtolower($to->label()),
        ));
    }

    public static function providerMismatch(): self
    {
        return new self('This pool only holds accounts from its own provider.');
    }

    public static function currencyMismatch(): self
    {
        return new self('This pool only holds accounts in its own currency.');
    }

    public static function poolNotEditable(): self
    {
        return new self('An archived pool cannot be changed.');
    }

    public static function limitBelowCommitment(): self
    {
        return new self('The limit cannot be set below what this account has already spent and committed.');
    }

    public static function duplicateExternalAccount(): self
    {
        return new self('This provider account is already in the inventory.');
    }
}
