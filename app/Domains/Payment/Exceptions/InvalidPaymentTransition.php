<?php

declare(strict_types=1);

namespace App\Domains\Payment\Exceptions;

use App\Domains\Payment\Enums\PaymentStatus;
use DomainException;

final class InvalidPaymentTransition extends DomainException
{
    public static function between(PaymentStatus $from, PaymentStatus $to): self
    {
        return new self("A payment cannot move from [{$from->value}] to [{$to->value}].");
    }
}
