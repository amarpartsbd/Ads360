<?php

declare(strict_types=1);

namespace App\Support\Values;

use App\Support\Exceptions\CurrencyMismatch;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An immutable monetary amount held as integer minor units (spec §60).
 *
 * Floats never take part in arithmetic here: `0.1 + 0.2` is not a money
 * operation. Amounts are integers, currency travels with the amount, and every
 * operation that cannot be exact makes its rounding policy explicit.
 */
final class Money implements JsonSerializable, Stringable
{
    public const ROUND_HALF_UP = 'half_up';

    public const ROUND_HALF_DOWN = 'half_down';

    public const ROUND_HALF_EVEN = 'half_even';

    public const ROUND_UP = 'up';

    public const ROUND_DOWN = 'down';

    private function __construct(
        public readonly int $minorUnits,
        public readonly Currency $currency,
    ) {}

    /** Build from minor units (paisa, cents). This is the storage representation. */
    public static function ofMinor(int $minorUnits, Currency|string $currency): self
    {
        return new self($minorUnits, self::resolveCurrency($currency));
    }

    /**
     * Build from a major-unit decimal string such as "1250.75".
     *
     * A string is required rather than a float so no precision is lost before
     * the value reaches this constructor.
     */
    public static function of(string|int $amount, Currency|string $currency): self
    {
        $currency = self::resolveCurrency($currency);
        $raw = trim((string) $amount);

        if (! preg_match('/^(?<sign>[+-]?)(?<whole>\d+)(?:\.(?<fraction>\d+))?$/', $raw, $m)) {
            throw new InvalidArgumentException("[{$raw}] is not a valid decimal amount.");
        }

        $fraction = $m['fraction'] ?? '';

        if (strlen($fraction) > $currency->scale) {
            throw new InvalidArgumentException(
                "Amount [{$raw}] has more precision than {$currency->code} allows ({$currency->scale} decimals)."
            );
        }

        $minor = (int) ($m['whole'].str_pad($fraction, $currency->scale, '0'));

        return new self($m['sign'] === '-' ? -$minor : $minor, $currency);
    }

    public static function zero(Currency|string $currency): self
    {
        return new self(0, self::resolveCurrency($currency));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    /**
     * Multiply by a ratio expressed as a decimal string (a fee rate, an
     * exchange rate). The rounding mode is required because the result is
     * rarely exact and the caller owns that policy.
     */
    public function multipliedBy(string|int $multiplier, string $rounding = self::ROUND_HALF_UP): self
    {
        $scaled = $this->scaleBy((string) $multiplier);

        return new self(
            self::round($scaled['numerator'], $scaled['denominator'], $rounding),
            $this->currency,
        );
    }

    public function dividedBy(string|int $divisor, string $rounding = self::ROUND_HALF_UP): self
    {
        $scaled = self::decimalToFraction((string) $divisor);

        if ($scaled['numerator'] === 0) {
            throw new InvalidArgumentException('Cannot divide a monetary amount by zero.');
        }

        return new self(
            self::round($this->minorUnits * $scaled['denominator'], $scaled['numerator'], $rounding),
            $this->currency,
        );
    }

    /**
     * Split into N parts without losing or inventing a single minor unit.
     * The remainder is distributed one unit at a time across the leading parts.
     *
     * @return list<self>
     */
    public function allocateEvenly(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('An amount must be split into at least one part.');
        }

        $sign = $this->minorUnits < 0 ? -1 : 1;
        $absolute = abs($this->minorUnits);
        $base = intdiv($absolute, $parts);
        $remainder = $absolute % $parts;

        $result = [];

        for ($i = 0; $i < $parts; $i++) {
            $share = $base + ($i < $remainder ? 1 : 0);
            $result[] = new self($sign * $share, $this->currency);
        }

        return $result;
    }

    /**
     * Split by integer ratios, again preserving the total exactly.
     *
     * @param  list<int>  $ratios
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        $total = array_sum($ratios);

        if ($ratios === [] || $total <= 0) {
            throw new InvalidArgumentException('Allocation ratios must contain at least one positive value.');
        }

        $sign = $this->minorUnits < 0 ? -1 : 1;
        $absolute = abs($this->minorUnits);
        $shares = [];
        $allocated = 0;

        foreach ($ratios as $ratio) {
            $share = intdiv($absolute * $ratio, $total);
            $shares[] = $share;
            $allocated += $share;
        }

        $remainder = $absolute - $allocated;

        for ($i = 0; $remainder > 0; $i++, $remainder--) {
            $shares[$i % count($shares)] += 1;
        }

        return array_map(fn (int $share): self => new self($sign * $share, $this->currency), $shares);
    }

    public function negated(): self
    {
        return new self(-$this->minorUnits, $this->currency);
    }

    public function absolute(): self
    {
        return new self(abs($this->minorUnits), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency->is($other->currency) && $this->minorUnits === $other->minorUnits;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits <=> $other->minorUnits;
    }

    /** The amount as a plain decimal string, e.g. "1250.75". Safe for display and storage. */
    public function toDecimal(): string
    {
        $sign = $this->minorUnits < 0 ? '-' : '';
        $absolute = (string) abs($this->minorUnits);

        if ($this->currency->scale === 0) {
            return $sign.$absolute;
        }

        $padded = str_pad($absolute, $this->currency->scale + 1, '0', STR_PAD_LEFT);

        return $sign.substr($padded, 0, -$this->currency->scale).'.'.substr($padded, -$this->currency->scale);
    }

    /** Human-readable form for UI and notifications, e.g. "BDT 1,250.75". */
    public function format(): string
    {
        $decimal = $this->toDecimal();
        $negative = str_starts_with($decimal, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($decimal, '-')), 2, null);

        // Grouped by hand rather than via number_format() so the result never
        // depends on the runtime locale.
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        return sprintf(
            '%s%s %s',
            $negative ? '-' : '',
            $this->currency->code,
            $fraction === null ? $grouped : "{$grouped}.{$fraction}",
        );
    }

    /**
     * @return array{amount: int, currency: string, decimal: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->minorUnits,
            'currency' => $this->currency->code,
            'decimal' => $this->toDecimal(),
            'formatted' => $this->format(),
        ];
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->currency->is($other->currency)) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }

    /**
     * @return array{numerator: int, denominator: int}
     */
    private function scaleBy(string $multiplier): array
    {
        $fraction = self::decimalToFraction($multiplier);

        return [
            'numerator' => $this->minorUnits * $fraction['numerator'],
            'denominator' => $fraction['denominator'],
        ];
    }

    /**
     * Turn a decimal string into an exact integer fraction so no float is involved.
     *
     * @return array{numerator: int, denominator: int}
     */
    private static function decimalToFraction(string $value): array
    {
        $raw = trim($value);

        if (! preg_match('/^(?<sign>[+-]?)(?<whole>\d+)(?:\.(?<fraction>\d+))?$/', $raw, $m)) {
            throw new InvalidArgumentException("[{$raw}] is not a valid decimal multiplier.");
        }

        $fraction = $m['fraction'] ?? '';
        $numerator = (int) ($m['whole'].$fraction);

        return [
            'numerator' => $m['sign'] === '-' ? -$numerator : $numerator,
            'denominator' => 10 ** strlen($fraction),
        ];
    }

    private static function round(int $numerator, int $denominator, string $mode): int
    {
        if ($denominator < 0) {
            $numerator = -$numerator;
            $denominator = -$denominator;
        }

        $sign = $numerator < 0 ? -1 : 1;
        $absolute = abs($numerator);
        $quotient = intdiv($absolute, $denominator);
        $remainder = $absolute % $denominator;

        if ($remainder === 0) {
            return $sign * $quotient;
        }

        $twiceRemainder = $remainder * 2;

        $roundAway = match ($mode) {
            self::ROUND_UP => true,
            self::ROUND_DOWN => false,
            self::ROUND_HALF_UP => $twiceRemainder >= $denominator,
            self::ROUND_HALF_DOWN => $twiceRemainder > $denominator,
            self::ROUND_HALF_EVEN => $twiceRemainder > $denominator
                || ($twiceRemainder === $denominator && $quotient % 2 !== 0),
            default => throw new InvalidArgumentException("Unknown rounding mode [{$mode}]."),
        };

        return $sign * ($roundAway ? $quotient + 1 : $quotient);
    }

    private static function resolveCurrency(Currency|string $currency): Currency
    {
        return $currency instanceof Currency ? $currency : Currency::of($currency);
    }
}
