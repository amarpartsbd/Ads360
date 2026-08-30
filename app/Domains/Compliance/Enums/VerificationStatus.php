<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Enums;

/**
 * Where an organization's business verification stands (spec §11).
 *
 * Transitions are controlled: `canTransitionTo()` is the single definition of
 * what may follow what, and both the action layer and the tests read it.
 */
enum VerificationStatus: string
{
    case NotSubmitted = 'NOT_SUBMITTED';
    case Pending = 'PENDING';
    case UnderReview = 'UNDER_REVIEW';
    case Verified = 'VERIFIED';
    case Rejected = 'REJECTED';
    case RequiresInformation = 'REQUIRES_INFORMATION';
    case Suspended = 'SUSPENDED';

    public function label(): string
    {
        return match ($this) {
            self::NotSubmitted => 'Not submitted',
            self::Pending => 'Pending review',
            self::UnderReview => 'Under review',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::RequiresInformation => 'More information needed',
            self::Suspended => 'Suspended',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::NotSubmitted => 'Business details have not been submitted yet.',
            self::Pending => 'Submitted and waiting to be picked up by the compliance team.',
            self::UnderReview => 'A reviewer is currently checking the submitted documents.',
            self::Verified => 'The business has been verified and the account is fully active.',
            self::Rejected => 'The submission was rejected. See the reviewer notes for details.',
            self::RequiresInformation => 'The reviewer needs additional information before deciding.',
            self::Suspended => 'Verification has been withdrawn and the account is restricted.',
        };
    }

    /** Only a verified business may transact on the platform. */
    public function isVerified(): bool
    {
        return $this === self::Verified;
    }

    /** Whether the client may still edit and submit their details. */
    public function isEditableByClient(): bool
    {
        return in_array($this, [self::NotSubmitted, self::RequiresInformation, self::Rejected], true);
    }

    /** Whether the submission is sitting in the compliance queue. */
    public function isAwaitingReview(): bool
    {
        return in_array($this, [self::Pending, self::UnderReview], true);
    }

    /**
     * The statuses that may legally follow this one.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::NotSubmitted => [self::Pending],
            // A submission may be picked up, decided directly, or sent back.
            self::Pending => [self::UnderReview, self::Verified, self::Rejected, self::RequiresInformation],
            self::UnderReview => [self::Verified, self::Rejected, self::RequiresInformation],
            // The client resubmits, which puts it back in the queue.
            self::RequiresInformation => [self::Pending],
            self::Rejected => [self::Pending],
            // A verified business can only lose that status by suspension.
            self::Verified => [self::Suspended],
            // Reinstating a suspended business requires a fresh review.
            self::Suspended => [self::Pending, self::Verified],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
