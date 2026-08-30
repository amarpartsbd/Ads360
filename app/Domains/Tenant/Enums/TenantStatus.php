<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Enums;

enum TenantStatus: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Archived = 'ARCHIVED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Archived => 'Archived',
        };
    }

    /** Only an active tenant may transact. Everything else is read-only at best. */
    public function isOperational(): bool
    {
        return $this === self::Active;
    }
}
