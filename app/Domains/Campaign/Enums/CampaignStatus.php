<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Enums;

/**
 * Where a campaign is in its life (spec §21).
 *
 * The transition table below is the authority, and it is deliberately narrow.
 * A campaign that could move anywhere from anywhere would let a draft skip
 * review, or a rejected campaign go live without anyone approving it — and the
 * money is reserved at approval, so an unreviewed campaign reaching the
 * provider means spending a client's balance nobody agreed to spend.
 */
enum CampaignStatus: string
{
    case Draft = 'DRAFT';
    case PendingReview = 'PENDING_REVIEW';
    case ChangesRequested = 'CHANGES_REQUESTED';
    case Rejected = 'REJECTED';
    case Approved = 'APPROVED';
    case Publishing = 'PUBLISHING';
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Archived = 'ARCHIVED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingReview => 'In review',
            self::ChangesRequested => 'Changes requested',
            self::Rejected => 'Rejected',
            self::Approved => 'Approved',
            self::Publishing => 'Publishing',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Failed => 'Could not publish',
            self::Archived => 'Archived',
        };
    }

    /** What to tell the client, in words that say what happens next. */
    public function clientMessage(): string
    {
        return match ($this) {
            self::Draft => 'Not submitted yet. You can keep editing.',
            self::PendingReview => 'Submitted for review. We will come back to you shortly.',
            self::ChangesRequested => 'We need a few changes before this can run. See the notes below.',
            self::Rejected => 'This campaign was not approved. See the notes below.',
            self::Approved => 'Approved. Your budget is held and the campaign is being set up.',
            self::Publishing => 'Being sent to the advertising platform.',
            self::Active => 'Running.',
            self::Paused => 'Paused. No spend is happening.',
            self::Completed => 'Finished. Any unspent budget has been returned to your wallet.',
            self::Failed => 'We could not set this campaign up. Nothing has been spent.',
            self::Archived => 'Archived.',
        };
    }

    /** Whether the client may still change the campaign's content. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::ChangesRequested], true);
    }

    /**
     * Whether the campaign has been through review and holds resources — a
     * wallet reservation and an allocated ad account.
     *
     * Failed is included: a campaign that could not be published still holds
     * its budget, which is what lets it be retried without a second review.
     */
    public function isResourced(): bool
    {
        return in_array($this, [
            self::Approved,
            self::Publishing,
            self::Active,
            self::Paused,
            self::Completed,
            self::Failed,
        ], true);
    }

    /** Whether the campaign exists at the provider. */
    public function isLive(): bool
    {
        return in_array($this, [self::Active, self::Paused], true);
    }

    /** Whether this is an end state — nothing further happens on its own. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Completed, self::Archived], true);
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingReview, self::Archived],
            self::PendingReview => [self::Approved, self::ChangesRequested, self::Rejected],
            self::ChangesRequested => [self::PendingReview, self::Archived],
            // A rejected campaign is copied, not revived: the client should see
            // what was refused rather than have it quietly change underneath.
            self::Rejected => [self::Archived],
            self::Approved => [self::Publishing, self::Failed],
            // Publishing returns to Approved when a retry is queued, so a
            // transient provider failure does not need an operator.
            self::Publishing => [self::Active, self::Failed, self::Approved],
            self::Active => [self::Paused, self::Completed, self::Failed],
            self::Paused => [self::Active, self::Completed],
            self::Completed => [self::Archived],
            // Failure is recoverable: the budget is still held, so a fixed
            // campaign can be sent again without going back through review.
            self::Failed => [self::Publishing, self::Completed, self::Archived],
            self::Archived => [],
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
