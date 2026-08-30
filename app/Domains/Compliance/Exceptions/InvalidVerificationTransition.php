<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Exceptions;

use App\Domains\Compliance\Enums\VerificationStatus;
use DomainException;

/**
 * A verification status change that the state machine does not allow (spec §11).
 */
final class InvalidVerificationTransition extends DomainException
{
    public static function between(VerificationStatus $from, VerificationStatus $to): self
    {
        return new self(
            "Verification cannot move from [{$from->value}] to [{$to->value}]."
        );
    }
}
