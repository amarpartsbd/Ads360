<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

enum PricingCalculation: string
{
    case Percentage = 'PERCENTAGE';
    case Fixed = 'FIXED';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage',
            self::Fixed => 'Fixed amount',
        };
    }
}
