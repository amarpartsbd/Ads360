<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Enums;

/**
 * How well a managed ad account is holding up (spec §17, §20).
 *
 * Health is derived from observation — sync failures, provider-reported
 * restrictions, spend against limits — and never set by a client.
 */
enum AdAccountHealth: string
{
    case Unknown = 'UNKNOWN';
    case Healthy = 'HEALTHY';
    case Degraded = 'DEGRADED';
    case AtRisk = 'AT_RISK';
    case Critical = 'CRITICAL';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Not yet checked',
            self::Healthy => 'Healthy',
            self::Degraded => 'Degraded',
            self::AtRisk => 'At risk',
            self::Critical => 'Critical',
        };
    }

    /**
     * Ordering for comparison and for deciding which of two observations is
     * the more serious. Higher is worse.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Healthy => 0,
            self::Unknown => 1,
            self::Degraded => 2,
            self::AtRisk => 3,
            self::Critical => 4,
        };
    }

    public function isWorseThan(self $other): bool
    {
        return $this->severity() > $other->severity();
    }

    /**
     * Whether allocation should prefer to leave this account alone. Degraded
     * accounts still take work — the whole point of tracking degradation is to
     * notice it before it becomes refusal.
     */
    public function isAllocatable(): bool
    {
        return in_array($this, [self::Healthy, self::Unknown, self::Degraded], true);
    }

    /** Whether an operator should be told about it (spec §20). */
    public function needsAttention(): bool
    {
        return in_array($this, [self::AtRisk, self::Critical], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
