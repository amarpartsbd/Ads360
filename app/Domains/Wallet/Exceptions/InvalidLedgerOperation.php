<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Exceptions;

use DomainException;

/**
 * An operation the ledger cannot represent — a non-positive amount, reversing
 * an entry twice, or drawing more from a hold than it contains.
 *
 * These are programming errors rather than things a client did, so the messages
 * are written for whoever is reading the stack trace.
 */
final class InvalidLedgerOperation extends DomainException
{
    public static function nonPositiveAmount(string $operation): self
    {
        return new self("A [{$operation}] must be for a positive amount.");
    }

    public static function alreadyReversed(string $publicId): self
    {
        return new self("Ledger entry [{$publicId}] has already been reversed.");
    }

    public static function notReversible(string $type): self
    {
        return new self("A [{$type}] entry cannot be reversed.");
    }

    public static function reservationClosed(string $publicId): self
    {
        return new self("Reservation [{$publicId}] is closed and cannot be drawn against.");
    }

    public static function exceedsReservation(string $publicId): self
    {
        return new self("That would draw more than reservation [{$publicId}] is holding.");
    }
}
