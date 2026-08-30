<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Enums;

/**
 * A reviewer's verdict on one document, so "more information needed" can point
 * at the specific file that was unreadable or expired.
 */
enum DocumentStatus: string
{
    case Pending = 'PENDING';
    case Accepted = 'ACCEPTED';
    case Rejected = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
        };
    }
}
