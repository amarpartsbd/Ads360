<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Enums;

/**
 * The state of one ad set or ad (spec §21).
 *
 * Shared by both because they follow the same path: drafted with the campaign,
 * published with it, and then either running or stopped.
 */
enum AdSetStatus: string
{
    case Draft = 'DRAFT';
    case Publishing = 'PUBLISHING';
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Rejected = 'REJECTED';
    case Failed = 'FAILED';
    case Archived = 'ARCHIVED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Publishing => 'Publishing',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Rejected => 'Rejected by the platform',
            self::Failed => 'Could not publish',
            self::Archived => 'Archived',
        };
    }

    /**
     * A provider's rejection is reported, never worked around. Spec §27 is
     * explicit: no attempt is made to bypass a review decision.
     */
    public function clientMessage(): string
    {
        return match ($this) {
            self::Draft => 'Not published yet.',
            self::Publishing => 'Being sent to the advertising platform.',
            self::Active => 'Running.',
            self::Paused => 'Paused.',
            self::Rejected => 'The advertising platform did not approve this. Edit it and resubmit.',
            self::Failed => 'We could not publish this. Nothing has been spent on it.',
            self::Archived => 'Archived.',
        };
    }

    public function isLive(): bool
    {
        return $this === self::Active;
    }
}
