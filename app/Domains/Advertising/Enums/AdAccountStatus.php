<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Enums;

/**
 * Where a managed ad account sits in its own lifecycle (spec §17).
 *
 * This is the platform's view of the account, not the provider's. An account
 * the provider is perfectly happy with can still be Retired here because we
 * stopped using it.
 */
enum AdAccountStatus: string
{
    case PendingSetup = 'PENDING_SETUP';
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Suspended = 'SUSPENDED';
    case Retired = 'RETIRED';

    public function label(): string
    {
        return match ($this) {
            self::PendingSetup => 'Pending setup',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Suspended => 'Suspended',
            self::Retired => 'Retired',
        };
    }

    /** Whether allocation may hand this account out (spec §19). */
    public function isAllocatable(): bool
    {
        return $this === self::Active;
    }

    /** Whether campaigns already on the account may keep spending. */
    public function permitsSpend(): bool
    {
        return $this === self::Active;
    }

    /**
     * Which statuses this one may move to. An account never comes back from
     * Retired: bringing it back would resurrect its spend history alongside
     * it, and the honest move is to register the provider account afresh.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingSetup => [self::Active, self::Retired],
            self::Active => [self::Paused, self::Suspended, self::Retired],
            self::Paused => [self::Active, self::Suspended, self::Retired],
            self::Suspended => [self::Active, self::Paused, self::Retired],
            self::Retired => [],
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
