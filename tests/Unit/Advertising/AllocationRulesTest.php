<?php

declare(strict_types=1);

namespace Tests\Unit\Advertising;

use App\Domains\Advertising\Values\AllocationRules;
use App\Domains\Compliance\Enums\VerificationStatus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The allocation rule value object (spec §18).
 *
 * These rules decide which client's money runs through which account, so the
 * object refuses anything it cannot interpret rather than falling back to a
 * default that would quietly widen a pool.
 */
final class AllocationRulesTest extends TestCase
{
    #[Test]
    public function the_default_is_the_conservative_one(): void
    {
        $rules = AllocationRules::default();

        $this->assertSame(VerificationStatus::Verified, $rules->requiredVerificationStatus);
        $this->assertTrue($rules->requireHealthyAccount);
        $this->assertNull($rules->allowedCountries);
    }

    #[Test]
    public function it_round_trips_through_an_array(): void
    {
        $rules = AllocationRules::fromArray([
            'required_verification_status' => 'VERIFIED',
            'minimum_wallet_balance_minor' => 500000,
            'allowed_countries' => ['bd', 'SG'],
            'blocked_categories' => ['Gambling'],
            'max_account_risk_score' => 40,
            'reserve_headroom_minor' => 100000,
            'require_healthy_account' => false,
        ]);

        $again = AllocationRules::fromArray($rules->toArray());

        $this->assertEquals($rules, $again);
        // Country codes are normalised on the way in, so a rule written as
        // "bd" matches an organization stored as "BD".
        $this->assertSame(['BD', 'SG'], $again->allowedCountries);
    }

    #[Test]
    public function an_unknown_verification_status_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AllocationRules::fromArray(['required_verification_status' => 'PROBABLY_FINE']);
    }

    #[Test]
    public function a_score_outside_its_range_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AllocationRules::fromArray(['max_account_risk_score' => 101]);
    }

    #[Test]
    public function a_negative_amount_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AllocationRules::fromArray(['minimum_wallet_balance_minor' => -1]);
    }

    #[Test]
    public function a_country_code_of_the_wrong_length_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AllocationRules::fromArray(['allowed_countries' => ['BGD']]);
    }

    #[Test]
    public function a_zero_client_cap_is_refused_because_it_would_mean_nobody(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AllocationRules::fromArray(['max_clients_per_account' => 0]);
    }

    #[Test]
    public function no_country_rule_permits_every_country(): void
    {
        $rules = AllocationRules::default();

        $this->assertTrue($rules->permitsCountry('BD'));
        $this->assertTrue($rules->permitsCountry(null));
    }

    #[Test]
    public function a_country_rule_refuses_a_client_with_no_country_recorded(): void
    {
        $rules = AllocationRules::fromArray(['allowed_countries' => ['BD']]);

        $this->assertTrue($rules->permitsCountry('bd'));
        $this->assertFalse($rules->permitsCountry('SG'));
        $this->assertFalse($rules->permitsCountry(null));
    }

    #[Test]
    public function a_blocked_category_wins_over_an_allowed_one(): void
    {
        $rules = AllocationRules::fromArray([
            'allowed_categories' => ['retail', 'gambling'],
            'blocked_categories' => ['gambling'],
        ]);

        $this->assertTrue($rules->permitsCategory('Retail'));
        $this->assertFalse($rules->permitsCategory('Gambling'));
    }

    #[Test]
    public function a_pool_that_names_categories_refuses_a_client_with_none(): void
    {
        $named = AllocationRules::fromArray(['allowed_categories' => ['retail']]);
        $open = AllocationRules::default();

        $this->assertFalse($named->permitsCategory(null));
        $this->assertTrue($open->permitsCategory(null));
    }
}
