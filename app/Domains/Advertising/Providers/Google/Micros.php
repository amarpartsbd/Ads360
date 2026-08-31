<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Providers\Google;

use App\Support\Values\Currency;
use InvalidArgumentException;

/**
 * Google Ads measures money in **micros**: a millionth of a currency unit
 * (spec §59, Rule 8).
 *
 * The platform measures money in the currency's own minor units — paisa for
 * BDT, cents for USD, whole yen for JPY — because that is what a bank
 * settles in and what the ledger stores. The two scales do not agree, and the
 * gap between them is a factor that depends on the currency:
 *
 *     BDT (2 decimals)   1 paisa  = 10,000 micros
 *     JPY (0 decimals)   1 yen    = 1,000,000 micros
 *
 * Getting this wrong is not a rounding error. A budget converted with a fixed
 * ×10,000 would be a hundred times too small in yen, and a spend figure read
 * back with the wrong factor would tell the reconciler a campaign had spent a
 * hundredth of what it did. So the currency is always required, and there is
 * no default.
 *
 * Every conversion here is integer arithmetic. No float ever holds a monetary
 * value on the way through (Rule 8, §59).
 */
final class Micros
{
    /** Micros in one whole unit of any currency. Google's constant, not ours. */
    private const PER_UNIT = 1_000_000;

    /**
     * Minor units to micros. Always exact: the factor is a power of ten and
     * no currency the platform supports has more than six decimal places.
     *
     * @throws InvalidArgumentException for a currency with no known scale,
     *                                  because guessing one would be a
     *                                  hundredfold error in the client's money
     */
    public static function fromMinor(int $minor, string $currency): int
    {
        return $minor * self::factor($currency);
    }

    /**
     * Micros back to minor units.
     *
     * Not exact in general: Google reports spend at micro resolution, and a
     * cost of 12,345 micros is 1.2345 paisa. Rounding to nearest is the honest
     * treatment — rounding down would understate every client's spend by a
     * fraction and leave the reconciler chasing a variance that grows with
     * volume (§78).
     *
     * Returns null for anything that is not a usable figure, rather than zero.
     * A campaign reported as having spent nothing is treated very differently
     * from one that did not answer (§87).
     */
    public static function toMinor(mixed $micros, string $currency): ?int
    {
        if ($micros === null) {
            return null;
        }

        if (! is_int($micros) && ! is_string($micros) && ! is_float($micros)) {
            return null;
        }

        $value = (string) $micros;

        // Google sends int64 fields as JSON strings, so a plain integer string
        // is the expected shape. Anything else is not a micros figure.
        if (! is_numeric($value)) {
            return null;
        }

        try {
            $factor = self::factor($currency);
        } catch (InvalidArgumentException) {
            return null;
        }

        /*
         * Parsed as an integer string rather than through a float. Google
         * sends micros as whole numbers; taking the integer path directly
         * keeps the promise that no monetary value passes through a float,
         * and the decimal fallback below exists only so an unexpected shape
         * degrades to a rounded figure rather than to nonsense.
         */
        $whole = self::integerString($value);

        if ($whole === null) {
            return null;
        }

        return self::divideRounded($whole, $factor);
    }

    /**
     * How many micros make one minor unit of this currency.
     *
     * @throws InvalidArgumentException
     */
    public static function factor(string $currency): int
    {
        $scale = Currency::of($currency)->scale;

        // 10^(6 - scale). A currency with more than six decimals would make
        // this fractional; none exists, and Currency would reject it anyway.
        return intdiv(self::PER_UNIT, 10 ** $scale);
    }

    /**
     * A micros figure as an integer, without a float in the path.
     *
     * `+12345` and `-12345` are accepted because JSON permits a sign; a
     * decimal is accepted and rounded, because refusing a figure Google sent
     * would lose real spend, and a fraction of a micro is not a sum anyone
     * can be wrong about.
     */
    private static function integerString(string $value): ?int
    {
        $trimmed = trim($value);

        if (preg_match('/^[+-]?\\d+$/', $trimmed) === 1) {
            return (int) $trimmed;
        }

        if (! is_numeric($trimmed)) {
            return null;
        }

        return (int) round((float) $trimmed);
    }

    /**
     * Integer division rounding half away from zero.
     *
     * Written out rather than done with `round()` on a float, because a float
     * cannot hold a large micros figure exactly and the whole point of this
     * class is that money never passes through one (Rule 8).
     */
    private static function divideRounded(int $numerator, int $denominator): int
    {
        $negative = $numerator < 0;
        $absolute = abs($numerator);

        $quotient = intdiv($absolute, $denominator);

        if ($absolute % $denominator >= intdiv($denominator + 1, 2)) {
            $quotient++;
        }

        return $negative ? -$quotient : $quotient;
    }
}
