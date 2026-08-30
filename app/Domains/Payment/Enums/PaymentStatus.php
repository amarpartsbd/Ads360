<?php

declare(strict_types=1);

namespace App\Domains\Payment\Enums;

/**
 * The lifecycle of a payment (spec §33, §34).
 *
 * Only VERIFIED credits the wallet, and only once. Every other state is either
 * on the way there or a terminal refusal.
 */
enum PaymentStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case AwaitingVerification = 'AWAITING_VERIFICATION';
    case Verified = 'VERIFIED';
    case Rejected = 'REJECTED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::AwaitingVerification => 'Awaiting verification',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isSettled(): bool
    {
        return $this === self::Verified;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Verified, self::Rejected, self::Failed, self::Cancelled], true);
    }

    /** Whether finance still has a decision to make on it. */
    public function awaitsFinance(): bool
    {
        return $this === self::AwaitingVerification;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::AwaitingVerification, self::Cancelled, self::Failed],
            self::Processing => [self::AwaitingVerification, self::Verified, self::Failed],
            self::AwaitingVerification => [self::Verified, self::Rejected],
            // Terminal. A verified payment is undone with a refund, never by
            // moving it back to an earlier state (spec §62).
            self::Verified, self::Rejected, self::Failed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
