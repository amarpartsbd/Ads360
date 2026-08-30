<?php

declare(strict_types=1);

namespace App\Support\Values;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An ISO-4217 currency together with the number of minor units it subdivides into.
 *
 * Money is stored and moved around as integer minor units (spec §59), so every
 * amount needs to know its own scale — BDT and USD use 2 decimals, JPY uses 0.
 */
final class Currency implements JsonSerializable, Stringable
{
    /**
     * Minor-unit exponents for the currencies the platform transacts in.
     * Anything absent from this map is rejected rather than silently assumed
     * to be a 2-decimal currency.
     *
     * @var array<string, int>
     */
    private const SCALES = [
        'BDT' => 2,
        'USD' => 2,
        'EUR' => 2,
        'GBP' => 2,
        'AUD' => 2,
        'CAD' => 2,
        'SGD' => 2,
        'AED' => 2,
        'INR' => 2,
        'MYR' => 2,
        'JPY' => 0,
    ];

    private function __construct(
        public readonly string $code,
        public readonly int $scale,
    ) {}

    public static function of(string $code): self
    {
        $normalised = strtoupper(trim($code));

        if (! isset(self::SCALES[$normalised])) {
            throw new InvalidArgumentException("Unsupported currency [{$code}].");
        }

        return new self($normalised, self::SCALES[$normalised]);
    }

    public static function supported(string $code): bool
    {
        return isset(self::SCALES[strtoupper(trim($code))]);
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::SCALES);
    }

    /** The number of minor units in one major unit (100 for BDT, 1 for JPY). */
    public function subunits(): int
    {
        return 10 ** $this->scale;
    }

    public function is(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function jsonSerialize(): string
    {
        return $this->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
