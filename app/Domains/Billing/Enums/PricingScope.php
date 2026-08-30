<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * How specific a pricing plan is (spec §36).
 *
 * Resolution walks from the most specific to the least: an organization
 * override beats a tenant plan, which beats the platform default.
 */
enum PricingScope: string
{
    case Platform = 'PLATFORM';
    case Tenant = 'TENANT';
    case Organization = 'ORGANIZATION';

    public function label(): string
    {
        return match ($this) {
            self::Platform => 'Platform default',
            self::Tenant => 'Tenant plan',
            self::Organization => 'Client override',
        };
    }

    /** Higher wins when more than one plan could apply. */
    public function specificity(): int
    {
        return match ($this) {
            self::Platform => 0,
            self::Tenant => 1,
            self::Organization => 2,
        };
    }
}
