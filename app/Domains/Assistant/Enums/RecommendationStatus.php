<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Enums;

/**
 * What has happened to a recommendation (spec §45).
 *
 * A recommendation is never deleted once made. What the platform suggested,
 * and what a client did about it, is exactly the record anyone would want if a
 * campaign built from a suggestion went badly (spec §62).
 */
enum RecommendationStatus: string
{
    case Offered = 'OFFERED';
    case Accepted = 'ACCEPTED';
    case Dismissed = 'DISMISSED';
    case Expired = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::Offered => 'Offered',
            self::Accepted => 'Accepted',
            self::Dismissed => 'Dismissed',
            self::Expired => 'No longer current',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Offered;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
