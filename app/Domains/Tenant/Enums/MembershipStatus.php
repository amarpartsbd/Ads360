<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Enums;

enum MembershipStatus: string
{
    case Invited = 'INVITED';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Revoked = 'REVOKED';

    public function label(): string
    {
        return match ($this) {
            self::Invited => 'Invited',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Revoked => 'Revoked',
        };
    }

    /** Only an active membership grants tenant context and permissions. */
    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }
}
