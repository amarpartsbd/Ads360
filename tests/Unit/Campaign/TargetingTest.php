<?php

declare(strict_types=1);

namespace Tests\Unit\Campaign;

use App\Domains\Campaign\Values\Targeting;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The targeting value object (spec §21, §27).
 *
 * Targeting decides where a client's money goes, so anything the object cannot
 * interpret is refused rather than dropped — a silently ignored narrowing is a
 * budget spent on the wrong people.
 */
final class TargetingTest extends TestCase
{
    #[Test]
    public function it_round_trips_through_an_array(): void
    {
        $targeting = Targeting::fromArray([
            'countries' => ['bd', 'SG'],
            'cities' => ['Dhaka'],
            'minimum_age' => 25,
            'maximum_age' => 45,
            'genders' => ['Female'],
            'devices' => ['MOBILE'],
            'interests' => ['cooking'],
        ]);

        $again = Targeting::fromArray($targeting->toArray());

        $this->assertEquals($targeting, $again);
        $this->assertSame(['BD', 'SG'], $again->countries);
        // Normalised on the way in, so a rule written either way matches.
        $this->assertSame(['female'], $again->genders);
        $this->assertSame(['mobile'], $again->devices);
    }

    #[Test]
    public function targeting_below_eighteen_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Advertising to minors carries obligations the platform does not
        // support, so the floor is not negotiable from stored data.
        Targeting::fromArray(['minimum_age' => 13]);
    }

    #[Test]
    public function an_age_range_the_wrong_way_round_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Targeting::fromArray(['minimum_age' => 50, 'maximum_age' => 30]);
    }

    #[Test]
    public function an_unrecognised_gender_is_refused_rather_than_ignored(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Targeting::fromArray(['genders' => ['everyone']]);
    }

    #[Test]
    public function an_unrecognised_device_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Targeting::fromArray(['devices' => ['smart-fridge']]);
    }

    #[Test]
    public function a_country_code_of_the_wrong_length_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Targeting::fromArray(['countries' => ['BGD']]);
    }

    #[Test]
    public function an_empty_entry_in_a_list_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Targeting::fromArray(['interests' => ['cooking', '   ']]);
    }

    #[Test]
    public function geography_is_recognised_from_any_of_the_three_levels(): void
    {
        $this->assertFalse(Targeting::everyone()->hasGeography());
        $this->assertTrue(Targeting::fromArray(['countries' => ['BD']])->hasGeography());
        $this->assertTrue(Targeting::fromArray(['cities' => ['Dhaka']])->hasGeography());
        $this->assertTrue(Targeting::fromArray(['regions' => ['dhaka-division']])->hasGeography());
    }

    #[Test]
    public function the_summary_reads_as_a_sentence_a_reviewer_can_scan(): void
    {
        $summary = Targeting::fromArray([
            'countries' => ['BD'],
            'minimum_age' => 25,
            'maximum_age' => 45,
            'genders' => ['female'],
        ])->summary();

        $this->assertStringContainsString('BD', $summary);
        $this->assertStringContainsString('25', $summary);
        $this->assertStringContainsString('female', $summary);
    }

    #[Test]
    public function duplicate_entries_are_collapsed(): void
    {
        $targeting = Targeting::fromArray(['countries' => ['BD', 'bd', 'BD']]);

        $this->assertSame(['BD'], $targeting->countries);
    }
}
