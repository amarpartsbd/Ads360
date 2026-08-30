<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Enums;

/**
 * Whether a pool is available to allocation (spec §18).
 */
enum PoolStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Archived = 'ARCHIVED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Archived => 'Archived',
        };
    }

    public function isAllocatable(): bool
    {
        return $this === self::Active;
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Archived],
            self::Active => [self::Paused, self::Archived],
            self::Paused => [self::Active, self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
