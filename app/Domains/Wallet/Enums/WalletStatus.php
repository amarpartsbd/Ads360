<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Enums;

enum WalletStatus: string
{
    case Active = 'ACTIVE';
    case Frozen = 'FROZEN';
    case Closed = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Frozen => 'Frozen',
            self::Closed => 'Closed',
        };
    }

    /** Whether funds may leave the wallet. */
    public function allowsDebit(): bool
    {
        return $this === self::Active;
    }

    /**
     * A frozen wallet still accepts money in: blocking a deposit would leave a
     * client's payment in limbo, which helps nobody. Only outflow is stopped.
     */
    public function allowsCredit(): bool
    {
        return $this !== self::Closed;
    }
}
