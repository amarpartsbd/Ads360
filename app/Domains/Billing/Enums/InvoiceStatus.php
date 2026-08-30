<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * The lifecycle of an invoice (spec §37).
 *
 * A draft is the platform's working copy and may still change. Everything from
 * ISSUED onwards has been seen by the client and is therefore frozen: a
 * correction is a void plus a credit note, never an edit.
 */
enum InvoiceStatus: string
{
    case Draft = 'DRAFT';
    case Issued = 'ISSUED';
    case PartiallyPaid = 'PARTIALLY_PAID';
    case Paid = 'PAID';
    case Overdue = 'OVERDUE';
    case Void = 'VOID';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
            self::Void => 'Void',
        };
    }

    /** Whether the document has left the platform and may no longer change. */
    public function isFinalised(): bool
    {
        return $this !== self::Draft;
    }

    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::Void], true);
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Issued, self::Void],
            self::Issued => [self::PartiallyPaid, self::Paid, self::Overdue, self::Void],
            self::PartiallyPaid => [self::Paid, self::Overdue, self::Void],
            self::Overdue => [self::PartiallyPaid, self::Paid, self::Void],
            self::Paid, self::Void => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
