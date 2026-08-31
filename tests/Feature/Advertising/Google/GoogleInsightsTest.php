<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising\Google;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Models\AdAccount;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesGoogleAds;
use Tests\TestCase;

/**
 * What Google reports, and how it is read (spec §20, §38, §78, §87).
 *
 * The recurring theme is that a figure Google does not report must arrive as
 * null and not as zero. A campaign shown as having spent nothing is treated
 * very differently from one that did not answer — the first releases a wallet
 * hold, the second should not.
 */
final class GoogleInsightsTest extends TestCase
{
    use FakesGoogleAds;
    use RefreshDatabase;

    private const CAMPAIGN = 'customers/1234567890/campaigns/555000111';

    private ?AdAccount $account = null;

    private function account(): AdAccount
    {
        return $this->account ??= AdAccount::factory()->create([
            'provider' => Provider::Google,
            'external_account_id' => '1234567890',
            'currency' => 'BDT',
        ]);
    }

    #[Test]
    public function cost_micros_become_minor_units_in_the_accounts_currency(): void
    {
        $this->fakeGoogle(['*googleAds:search*' => Http::response($this->googleSearch([[
            'campaign' => ['id' => '555000111', 'status' => 'ENABLED'],
            'metrics' => [
                'costMicros' => '12500000000',
                'impressions' => '48213',
                'clicks' => '1120',
                'conversions' => 37.4,
            ],
        ]]))]);

        $insights = $this->googleAdapter()->campaignInsights($this->account(), self::CAMPAIGN);

        // 12.5 billion micros is 12,500 taka is 1,250,000 poisha.
        $this->assertSame(1_250_000, $insights->spendMinor);
        $this->assertSame('BDT', $insights->currency);
        $this->assertSame(48213, $insights->impressions);
        $this->assertSame(1120, $insights->clicks);
        $this->assertSame('ENABLED', $insights->status);
    }

    #[Test]
    public function fractional_conversions_are_rounded_rather_than_truncated(): void
    {
        $this->fakeGoogle(['*googleAds:search*' => Http::response($this->googleSearch([[
            'campaign' => ['id' => '555000111'],
            'metrics' => ['costMicros' => '0', 'conversions' => 0.6],
        ]]))]);

        // Google credits a click shared between campaigns as a fraction to
        // each. Truncating would report nothing happened.
        $this->assertSame(1, $this->googleAdapter()->campaignInsights($this->account(), self::CAMPAIGN)->conversions);
    }

    #[Test]
    public function a_campaign_that_has_never_served_reports_nothing_not_zero(): void
    {
        $this->fakeGoogle(['*googleAds:search*' => Http::response($this->googleSearch([]))]);

        $insights = $this->googleAdapter()->campaignInsights($this->account(), self::CAMPAIGN);

        // A campaign published minutes ago must not have its wallet hold
        // released as though it had finished.
        $this->assertNull($insights->spendMinor);
        $this->assertFalse($insights->reportsSpend());
    }

    #[Test]
    public function lifetime_insights_carry_no_date_filter(): void
    {
        $this->fakeGoogle(['*googleAds:search*' => Http::response($this->googleSearch([]))]);

        $this->googleAdapter()->campaignInsights($this->account(), self::CAMPAIGN);

        // The ledger reconciles against spend to date, not spend in a window.
        $this->assertStringNotContainsString('segments.date', $this->sentQueries()[0]);
    }

    #[Test]
    public function daily_insights_ask_for_one_row_a_day_across_the_window(): void
    {
        $this->fakeGoogle(['*googleAds:search*' => Http::response($this->googleSearch([]))]);

        $this->googleAdapter()->campaignDailyInsights(
            $this->account(),
            self::CAMPAIGN,
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-07'),
        );

        $query = $this->sentQueries()[0];

        // `segments.date` in the SELECT is what makes Google return a row per
        // day rather than one row for the range.
        $this->assertStringContainsString('SELECT segments.date', $query);
        $this->assertStringContainsString("BETWEEN '2026-08-01' AND '2026-08-07'", $query);
    }

    #[Test]
    public function a_day_of_performance_is_read_with_its_own_date_and_currency(): void
    {
        $this->fakeGoogle(['*googleAds:search*' => Http::response($this->googleSearch([[
            'segments' => ['date' => '2026-08-03'],
            'metrics' => [
                'costMicros' => '4500000000',
                'impressions' => '9000',
                'clicks' => '210',
                'conversions' => 4.0,
                'conversionsValue' => 18500.5,
            ],
        ]]))]);

        $rows = $this->googleAdapter()->campaignDailyInsights(
            $this->account(),
            self::CAMPAIGN,
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-07'),
        );

        $this->assertCount(1, $rows);
        $this->assertSame('2026-08-03', $rows[0]->date->format('Y-m-d'));
        $this->assertSame(450_000, $rows[0]->spendMinor);

        /*
         * `conversions_value` is a decimal in the account's currency, not
         * micros — Google is inconsistent about this, and reading it as micros
         * would report a conversion worth 18,500 taka as worth almost nothing.
         */
        $this->assertSame(1_850_050, $rows[0]->conversionValueMinor);
    }

    #[Test]
    public function reach_is_reported_as_unknown_because_google_does_not_publish_it(): void
    {
        $this->fakeGoogle(['*googleAds:search*' => Http::response($this->googleSearch([[
            'segments' => ['date' => '2026-08-03'],
            'metrics' => ['costMicros' => '1000000', 'impressions' => '10'],
        ]]))]);

        $rows = $this->googleAdapter()->campaignDailyInsights(
            $this->account(),
            self::CAMPAIGN,
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-07'),
        );

        // A zero here would be drawn on a client's chart as a day nobody saw
        // their ads (§87).
        $this->assertNull($rows[0]->reach);
    }

    #[Test]
    public function an_account_reports_its_status_billing_and_month_to_date_spend(): void
    {
        $today = (new DateTimeImmutable('now', new \DateTimeZone('Asia/Dhaka')))->format('Y-m-d');

        $this->fakeGoogle(['*googleAds:search*' => Http::sequence()
            ->push($this->googleSearch([['customer' => [
                'id' => '1234567890',
                'descriptiveName' => 'Amar Parts — Retail',
                'currencyCode' => 'BDT',
                'timeZone' => 'Asia/Dhaka',
                'status' => 'ENABLED',
            ]]]))
            ->push($this->googleSearch([
                ['metrics' => ['costMicros' => '3000000000'], 'segments' => ['date' => '2026-08-01']],
                ['metrics' => ['costMicros' => '1000000000'], 'segments' => ['date' => $today]],
            ]))
            ->push($this->googleSearch([['billingSetup' => ['id' => '1', 'status' => 'APPROVED']]])),
        ]);

        $state = $this->googleAdapter()->accountState('123-456-7890');

        $this->assertSame('ACTIVE', $state->status);
        $this->assertSame('CURRENT', $state->billingStatus);
        $this->assertSame('BDT', $state->currency);

        // 4 billion micros across the month; 1 billion of it today.
        $this->assertSame(400_000, $state->spentThisMonthMinor);
        $this->assertSame(100_000, $state->spentTodayMinor);
    }

    #[Test]
    public function a_spend_limit_google_does_not_expose_is_reported_as_unknown(): void
    {
        $this->fakeGoogle(['*googleAds:search*' => Http::sequence()
            ->push($this->googleSearch([['customer' => [
                'id' => '1234567890', 'currencyCode' => 'BDT', 'status' => 'ENABLED',
            ]]]))
            ->push($this->googleSearch([]))
            ->push($this->googleSearch([['billingSetup' => ['status' => 'APPROVED']]])),
        ]);

        $state = $this->googleAdapter()->accountState('1234567890');

        /*
         * Reporting a limit the adapter never read would have the allocation
         * engine make decisions from a figure that was never true — which is
         * why supports(SpendLimits) says no (§20, §87).
         */
        $this->assertNull($state->dailySpendLimitMinor);
        $this->assertNull($state->monthlySpendLimitMinor);
    }

    #[Test]
    public function an_account_with_no_billing_set_up_is_reported_as_missing_a_payment_method(): void
    {
        $this->fakeGoogle(['*googleAds:search*' => Http::sequence()
            ->push($this->googleSearch([['customer' => [
                'id' => '1234567890', 'currencyCode' => 'BDT', 'status' => 'ENABLED',
            ]]]))
            ->push($this->googleSearch([]))
            // No billing setup rows at all.
            ->push($this->googleSearch([])),
        ]);

        // The account is enabled and will refuse the first ad; better to know
        // now than when a client's campaign fails to serve.
        $this->assertSame(
            'PAYMENT_METHOD_MISSING',
            $this->googleAdapter()->accountState('1234567890')->billingStatus,
        );
    }

    #[Test]
    public function a_suspended_account_says_so_rather_than_being_smoothed_over(): void
    {
        $this->fakeGoogle(['*googleAds:search*' => Http::sequence()
            ->push($this->googleSearch([['customer' => [
                'id' => '1234567890', 'currencyCode' => 'BDT', 'status' => 'SUSPENDED',
            ]]]))
            ->push($this->googleSearch([])),
        ]);

        $state = $this->googleAdapter()->accountState('1234567890');

        $this->assertSame('SUSPENDED', $state->status);
        $this->assertNotNull($state->disapprovalReason);
    }

    #[Test]
    public function spend_in_a_currency_with_no_known_scale_is_unknown_rather_than_wrong(): void
    {
        $this->fakeGoogle(['*googleAds:search*' => Http::sequence()
            ->push($this->googleSearch([['customer' => [
                'id' => '1234567890', 'currencyCode' => 'XYZ', 'status' => 'ENABLED',
            ]]]))
            ->push($this->googleSearch([['billingSetup' => ['status' => 'APPROVED']]])),
        ]);

        $state = $this->googleAdapter()->accountState('1234567890');

        // Without a scale a micros figure cannot be converted at all, and a
        // guess would be wrong by a factor of a hundred.
        $this->assertNull($state->spentTodayMinor);
        $this->assertNull($state->spentThisMonthMinor);
    }
}
