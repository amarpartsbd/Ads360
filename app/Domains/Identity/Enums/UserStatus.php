<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

enum UserStatus: string
{
    case Invited = 'INVITED';
    case PendingVerification = 'PENDING_VERIFICATION';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Deactivated = 'DEACTIVATED';

    public function label(): string
    {
        return match ($this) {
            self::Invited => 'Invited',
            self::PendingVerification => 'Pending verification',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Deactivated => 'Deactivated',
        };
    }

    /** Whether the account may authenticate at all. */
    public function canAuthenticate(): bool
    {
        return in_array($this, [self::Active, self::PendingVerification], true);
    }
}
