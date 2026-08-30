<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Enums;

/**
 * The lifecycle of a budget hold (spec §32).
 *
 * Money is reserved when a campaign is approved, drawn down as spend is
 * reported, and whatever remains is released when the campaign ends.
 */
enum ReservationStatus: string
{
    case Held = 'HELD';
    case PartiallyCaptured = 'PARTIALLY_CAPTURED';
    case Captured = 'CAPTURED';
    case Released = 'RELEASED';

    public function label(): string
    {
        return match ($this) {
            self::Held => 'Held',
            self::PartiallyCaptured => 'Partially spent',
            self::Captured => 'Fully spent',
            self::Released => 'Released',
        };
    }

    /** Whether the reservation can still be drawn against or given back. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Held, self::PartiallyCaptured], true);
    }
}
