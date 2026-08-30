<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Enums;

/**
 * Where an organization sits in the onboarding flow of spec §10.
 */
enum OrganizationStatus: string
{
    case Pending = 'PENDING';
    case UnderReview = 'UNDER_REVIEW';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Closed = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending setup',
            self::UnderReview => 'Under review',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    public function isOperational(): bool
    {
        return $this === self::Active;
    }
}
