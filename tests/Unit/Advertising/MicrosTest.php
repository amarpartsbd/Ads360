<?php

declare(strict_types=1);

namespace Tests\Unit\Advertising;

use App\Domains\Advertising\Providers\Google\Micros;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Micros against minor units (spec §59, Rule 8).
 *
 * The failure this guards against is quiet and expensive: a fixed conversion
 * factor is right for a two-decimal currency and a hundred times wrong for a
 * zero-decimal one. A budget converted with the wrong factor is either a
 * hundredth of what the client authorised or a hundred times it.
 */
final class MicrosTest extends TestCase
{
    #[Test]
    #[DataProvider('conversions')]
    public function minor_units_convert_to_micros_by_the_currency_scale(
        int $minor,
        string $currency,
        int $micros,
    ): void {
        $this->assertSame($micros, Micros::fromMinor($minor, $currency));
    }

    /**
     * @return array<string, array{int, string, int}>
     */
    public static function conversions(): array
    {
        return [
            // 100 paisa is one taka is a million micros.
            'one taka' => [100, 'BDT', 1_000_000],
            'one poisha' => [1, 'BDT', 10_000],
            'a large budget' => [2_500_000, 'BDT', 25_000_000_000],
            'one cent' => [1, 'USD', 10_000],

            // Yen has no minor unit at all: one yen *is* the minor unit, so it
            // is a whole million micros rather than ten thousand.
            'one yen' => [1, 'JPY', 1_000_000],
            'nothing' => [0, 'BDT', 0],
        ];
    }

    #[Test]
    public function micros_convert_back_to_minor_units(): void
    {
        $this->assertSame(100, Micros::toMinor('1000000', 'BDT'));
        $this->assertSame(1, Micros::toMinor('1000000', 'JPY'));
        $this->assertSame(2_500_000, Micros::toMinor('25000000000', 'BDT'));
    }

    #[Test]
    public function a_figure_finer_than_a_minor_unit_rounds_to_nearest(): void
    {
        // 1.2345 paisa. Rounding down every time would understate every
        // client's spend by a fraction that grows with volume (§78).
        $this->assertSame(1, Micros::toMinor('12345', 'BDT'));
        $this->assertSame(2, Micros::toMinor('15000', 'BDT'));
        $this->assertSame(1, Micros::toMinor('14999', 'BDT'));
    }

    #[Test]
    public function a_large_figure_converts_on_the_integer_path(): void
    {
        /*
         * Past the range where a double holds every integer exactly. The
         * conversion reads the digits and divides with a remainder rather than
         * multiplying a float, so the half-rounding still lands on the right
         * side at this magnitude.
         */
        $this->assertSame(90_071_992_547_410, Micros::toMinor('900719925474099999', 'BDT'));
        $this->assertSame(90_071_992_547_410, Micros::toMinor('900719925474095000', 'BDT'));
        $this->assertSame(90_071_992_547_409, Micros::toMinor('900719925474094999', 'BDT'));
    }

    #[Test]
    public function a_negative_figure_rounds_away_from_zero_symmetrically(): void
    {
        $this->assertSame(-1, Micros::toMinor('-12345', 'BDT'));
        $this->assertSame(-2, Micros::toMinor('-15000', 'BDT'));
    }

    #[Test]
    public function an_unreadable_figure_is_null_rather_than_zero(): void
    {
        // A campaign reported as having spent nothing is treated very
        // differently from one that did not answer (§87).
        $this->assertNull(Micros::toMinor(null, 'BDT'));
        $this->assertNull(Micros::toMinor('not a number', 'BDT'));
        $this->assertNull(Micros::toMinor(['1000000'], 'BDT'));
    }

    #[Test]
    public function an_unknown_currency_is_null_rather_than_guessed(): void
    {
        $this->assertNull(Micros::toMinor('1000000', 'XYZ'));
    }

    #[Test]
    public function converting_to_micros_in_an_unknown_currency_raises_rather_than_guessing(): void
    {
        // Guessing a scale here would send Google a budget a hundred times
        // the client's authorisation.
        $this->expectException(InvalidArgumentException::class);

        Micros::fromMinor(100, 'XYZ');
    }

    #[Test]
    public function every_supported_currency_round_trips(): void
    {
        foreach (\App\Support\Values\Currency::codes() as $code) {
            $micros = Micros::fromMinor(123_456, $code);

            $this->assertSame(
                123_456,
                Micros::toMinor((string) $micros, $code),
                "{$code} did not survive the round trip.",
            );
        }
    }
}
