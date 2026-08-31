<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Enums;

/**
 * What a spend comparison found (spec §78).
 */
enum ReconciliationStatus: string
{
    case Balanced = 'BALANCED';
    case Investigating = 'INVESTIGATING';
    case Resolved = 'RESOLVED';

    public function label(): string
    {
        return match ($this) {
            self::Balanced => 'Balanced',
            self::Investigating => 'Needs investigation',
            self::Resolved => 'Resolved',
        };
    }

    /** Whether anyone needs to look at it. */
    public function needsAttention(): bool
    {
        return $this === self::Investigating;
    }
}
