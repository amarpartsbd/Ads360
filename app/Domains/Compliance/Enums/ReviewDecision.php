<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Enums;

/**
 * What a compliance reviewer did (spec §11).
 *
 * Each decision maps to exactly one resulting verification status, so a review
 * cannot record a decision and a status that disagree.
 */
enum ReviewDecision: string
{
    case Claimed = 'CLAIMED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case InformationRequested = 'INFORMATION_REQUESTED';
    case Suspended = 'SUSPENDED';

    public function label(): string
    {
        return match ($this) {
            self::Claimed => 'Picked up for review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::InformationRequested => 'More information requested',
            self::Suspended => 'Verification suspended',
        };
    }

    public function resultingStatus(): VerificationStatus
    {
        return match ($this) {
            self::Claimed => VerificationStatus::UnderReview,
            self::Approved => VerificationStatus::Verified,
            self::Rejected => VerificationStatus::Rejected,
            self::InformationRequested => VerificationStatus::RequiresInformation,
            self::Suspended => VerificationStatus::Suspended,
        };
    }

    /**
     * Whether the decision must carry a message the client can read. Approving
     * needs no explanation; refusing or asking for more always does.
     */
    public function requiresClientMessage(): bool
    {
        return in_array($this, [self::Rejected, self::InformationRequested, self::Suspended], true);
    }
}
