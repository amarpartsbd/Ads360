<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Enums;

enum InvitationStatus: string
{
    case Pending = 'PENDING';
    case Accepted = 'ACCEPTED';
    case Revoked = 'REVOKED';
    case Expired = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Accepted => 'Accepted',
            self::Revoked => 'Revoked',
            self::Expired => 'Expired',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
