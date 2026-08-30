<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Exceptions;

use App\Domains\Wallet\Enums\WalletStatus;
use DomainException;

final class WalletUnavailable extends DomainException
{
    public static function cannotDebit(WalletStatus $status): self
    {
        return new self(
            "Funds cannot leave a {$status->label()} wallet. Contact support if this is unexpected."
        );
    }

    public static function cannotCredit(WalletStatus $status): self
    {
        return new self("A {$status->label()} wallet cannot receive funds.");
    }
}
