<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Exceptions\CurrencyMismatch;
use App\Support\Values\Currency;
use App\Support\Values\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Money value object (spec §60, §67).
 *
 * The behaviour worth guarding is that arithmetic is exact, currencies never
 * mix implicitly, and splitting an amount neither loses nor invents a unit.
 */
final class MoneyTest extends TestCase
{
    #[Test]
    public function it_parses_a_decimal_string_into_minor_units(): void
    {
        $this->assertSame(125075, Money::of('1250.75', 'BDT')->minorUnits);
        $this->assertSame(100, Money::of('1', 'BDT')->minorUnits);
        $this->assertSame(-2550, Money::of('-25.50', 'BDT')->minorUnits);
    }

    #[Test]
    public function it_respects_currencies_with_no_minor_units(): void
    {
        $yen = Money::of('1200', 'JPY');

        $this->assertSame(1200, $yen->minorUnits);
        $this->assertSame('1200', $yen->toDecimal());
    }

    #[Test]
    public function it_rejects_more_precision_than_the_currency_allows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('10.999', 'BDT');
    }

    #[Test]
    public function it_rejects_an_unsupported_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('10.00', 'XYZ');
    }

    #[Test]
    public function the_classic_floating_point_error_does_not_occur(): void
    {
        // 0.1 + 0.2 is 0.30000000000000004 in binary floating point. Here it is
        // exactly 0.30, because the arithmetic happens on integers.
        $sum = Money::of('0.10', 'BDT')->plus(Money::of('0.20', 'BDT'));

        $this->assertSame('0.30', $sum->toDecimal());
        $this->assertTrue($sum->equals(Money::of('0.30', 'BDT')));
    }

    #[Test]
    public function adding_different_currencies_is_refused(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::of('100.00', 'BDT')->plus(Money::of('100.00', 'USD'));
    }

    #[Test]
    public function comparing_different_currencies_is_refused(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::of('100.00', 'BDT')->greaterThan(Money::of('1.00', 'USD'));
    }

    #[Test]
    public function equality_accounts_for_currency(): void
    {
        $this->assertFalse(Money::of('100.00', 'BDT')->equals(Money::of('100.00', 'USD')));
        $this->assertTrue(Money::of('100.00', 'BDT')->equals(Money::ofMinor(10000, 'BDT')));
    }

    #[Test]
    public function multiplying_by_a_fee_rate_rounds_as_instructed(): void
    {
        $amount = Money::of('999.99', 'BDT');

        // A 7.5% platform fee.
        $this->assertSame('75.00', $amount->multipliedBy('0.075')->toDecimal());
        $this->assertSame('74.99', $amount->multipliedBy('0.075', Money::ROUND_DOWN)->toDecimal());
    }

    /**
     * @return list<array{string, string, string}>
     */
    public static function roundingCases(): array
    {
        return [
            // amount, mode, expected — each case sits exactly on a half unit.
            ['0.5', Money::ROUND_HALF_UP, '0.03'],
            ['0.5', Money::ROUND_HALF_DOWN, '0.02'],
            ['0.5', Money::ROUND_HALF_EVEN, '0.02'],
            ['0.5', Money::ROUND_UP, '0.03'],
            ['0.5', Money::ROUND_DOWN, '0.02'],
        ];
    }

    #[Test]
    #[DataProvider('roundingCases')]
    public function rounding_modes_behave_as_documented(string $rate, string $mode, string $expected): void
    {
        // 0.05 × 0.5 = 0.025, exactly halfway between 0.02 and 0.03.
        $this->assertSame($expected, Money::of('0.05', 'BDT')->multipliedBy($rate, $mode)->toDecimal());
    }

    #[Test]
    public function rounding_is_symmetric_for_negative_amounts(): void
    {
        $this->assertSame('-0.03', Money::of('-0.05', 'BDT')->multipliedBy('0.5')->toDecimal());
        $this->assertSame('-0.02', Money::of('-0.05', 'BDT')->multipliedBy('0.5', Money::ROUND_DOWN)->toDecimal());
    }

    #[Test]
    public function dividing_by_zero_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('100.00', 'BDT')->dividedBy('0');
    }

    #[Test]
    public function an_even_split_preserves_the_total_exactly(): void
    {
        $parts = Money::of('100.00', 'BDT')->allocateEvenly(3);

        $this->assertCount(3, $parts);
        $this->assertSame(['33.34', '33.33', '33.33'], array_map(
            static fn (Money $part): string => $part->toDecimal(),
            $parts,
        ));

        $total = array_reduce(
            $parts,
            static fn (Money $carry, Money $part): Money => $carry->plus($part),
            Money::zero('BDT'),
        );

        $this->assertTrue($total->equals(Money::of('100.00', 'BDT')), 'Splitting lost or invented a unit.');
    }

    #[Test]
    public function a_ratio_split_preserves_the_total_exactly(): void
    {
        $parts = Money::of('100.00', 'BDT')->allocate([1, 1, 1]);

        $total = array_reduce(
            $parts,
            static fn (Money $carry, Money $part): Money => $carry->plus($part),
            Money::zero('BDT'),
        );

        $this->assertTrue($total->equals(Money::of('100.00', 'BDT')));

        $weighted = Money::of('1000.00', 'BDT')->allocate([70, 30]);
        $this->assertSame('700.00', $weighted[0]->toDecimal());
        $this->assertSame('300.00', $weighted[1]->toDecimal());
    }

    #[Test]
    public function a_negative_amount_splits_without_loss(): void
    {
        $parts = Money::of('-100.00', 'BDT')->allocateEvenly(3);

        $total = array_reduce(
            $parts,
            static fn (Money $carry, Money $part): Money => $carry->plus($part),
            Money::zero('BDT'),
        );

        $this->assertSame('-100.00', $total->toDecimal());
    }

    #[Test]
    public function it_formats_with_currency_and_thousands_separators(): void
    {
        $this->assertSame('BDT 1,250,000.75', Money::of('1250000.75', 'BDT')->format());
        $this->assertSame('-BDT 1,250.00', Money::of('-1250.00', 'BDT')->format());
        $this->assertSame('BDT 0.05', Money::of('0.05', 'BDT')->format());
    }

    #[Test]
    public function it_serialises_amount_and_currency_together(): void
    {
        $payload = Money::of('1250.75', 'BDT')->jsonSerialize();

        $this->assertSame(125075, $payload['amount']);
        $this->assertSame('BDT', $payload['currency']);
        $this->assertSame('1250.75', $payload['decimal']);
    }

    #[Test]
    public function currency_scale_is_known_per_currency(): void
    {
        $this->assertSame(2, Currency::of('BDT')->scale);
        $this->assertSame(0, Currency::of('JPY')->scale);
        $this->assertSame(100, Currency::of('USD')->subunits());
        $this->assertTrue(Currency::supported('bdt'));
        $this->assertFalse(Currency::supported('ZZZ'));
    }
}
