<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Enums;

/**
 * The provider's billing standing for a managed ad account (spec §17).
 *
 * Separate from health because the failure is different in kind: a billing
 * problem stops spend outright and is fixed by the platform's finance team,
 * not by changing how campaigns run.
 */
enum AdAccountBillingStatus: string
{
    case Unknown = 'UNKNOWN';
    case Current = 'CURRENT';
    case PaymentMethodMissing = 'PAYMENT_METHOD_MISSING';
    case PaymentFailed = 'PAYMENT_FAILED';
    case LimitReached = 'LIMIT_REACHED';
    case Suspended = 'SUSPENDED';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Not yet checked',
            self::Current => 'Current',
            self::PaymentMethodMissing => 'No payment method',
            self::PaymentFailed => 'Payment failed',
            self::LimitReached => 'Billing limit reached',
            self::Suspended => 'Suspended by provider',
        };
    }

    /** Whether the provider will accept spend on this account right now. */
    public function permitsSpend(): bool
    {
        return in_array($this, [self::Current, self::Unknown], true);
    }

    public function needsAttention(): bool
    {
        return ! in_array($this, [self::Current, self::Unknown], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
