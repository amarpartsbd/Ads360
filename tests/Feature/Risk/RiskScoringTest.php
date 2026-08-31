<?php

declare(strict_types=1);

namespace Tests\Feature\Risk;

use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Client\Enums\RiskFactor;
use App\Domains\Client\Enums\RiskLevel;
use App\Domains\Client\Models\OrganizationRiskProfile;
use App\Domains\Client\Services\RiskAssessor;
use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Payment\Enums\PaymentStatus;
use App\Domains\Payment\Models\Payment;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * The client risk engine (spec §12).
 *
 * Two properties are being defended here above all others: that the same
 * account produces the same score every time, and that every point can be read
 * back as a reason. A score nobody can reproduce or explain cannot be appealed
 * or corrected, and it would be deciding things about people's businesses.
 */
final class RiskScoringTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    private function organization(): Organization
    {
        $workspace = $this->createWorkspace();

        /** @var Organization $organization */
        $organization = $workspace['organization'];

        // Aged past the new-account factor unless a test wants it.
        $organization->forceFill(['created_at' => now()->subMonths(6)])->save();

        return $organization->refresh();
    }

    #[Test]
    public function the_same_account_scores_the_same_every_time(): void
    {
        $organization = $this->organization();
        $this->createVerificationProfile($organization, VerificationStatus::Verified);

        $assessor = app(RiskAssessor::class);

        $first = $assessor->assess($organization);
        $second = $assessor->assess($organization);

        // No model, no randomness, no learned weight.
        $this->assertSame($first->score, $second->score);
        $this->assertSame($first->toArray(), $second->toArray());
    }

    #[Test]
    public function a_verified_quiet_account_scores_zero(): void
    {
        $organization = $this->organization();
        $this->createVerificationProfile($organization, VerificationStatus::Verified);

        $assessment = app(RiskAssessor::class)->assess($organization);

        $this->assertSame(0, $assessment->score);
        $this->assertSame(RiskLevel::Low, $assessment->level);
        $this->assertSame([], $assessment->contributions);
    }

    #[Test]
    public function an_unverified_business_carries_the_heaviest_single_factor(): void
    {
        $organization = $this->organization();

        $assessment = app(RiskAssessor::class)->assess($organization);

        $this->assertSame(RiskFactor::VerificationIncomplete->ceiling(), $assessment->score);
        $this->assertSame(
            RiskFactor::VerificationIncomplete,
            $assessment->contributions[0]->factor,
        );
    }

    #[Test]
    public function verification_in_progress_weighs_less_than_never_submitted(): void
    {
        $waiting = $this->organization();
        $this->createVerificationProfile($waiting, VerificationStatus::UnderReview);

        $never = $this->organization();

        $assessor = app(RiskAssessor::class);

        // A client who has done what was asked and is waiting on us should not
        // score the same as one who has not started.
        $this->assertLessThan(
            $assessor->assess($never)->score,
            $assessor->assess($waiting)->score,
        );
    }

    #[Test]
    public function every_point_comes_with_a_sentence_a_person_can_read(): void
    {
        $organization = $this->organization();
        $this->failPayments($organization, 2);

        $assessment = app(RiskAssessor::class)->assess($organization);

        $this->assertNotSame([], $assessment->contributions);

        foreach ($assessment->contributions as $contribution) {
            $this->assertGreaterThan(0, $contribution->points);
            $this->assertNotSame('', trim($contribution->detail));
        }

        // The reasons add up to the score exactly — there is no unexplained
        // remainder anywhere in it.
        $this->assertSame(
            $assessment->score,
            array_sum(array_map(fn ($c): int => $c->points, $assessment->contributions)),
        );
    }

    #[Test]
    public function a_factor_cannot_exceed_its_own_ceiling(): void
    {
        $organization = $this->organization();

        // Far more failures than the ceiling allows for.
        $this->failPayments($organization, 40);

        $assessment = app(RiskAssessor::class)->assess($organization);

        $payments = collect($assessment->contributions)
            ->firstWhere(fn ($c): bool => $c->factor === RiskFactor::PaymentFailures);

        $this->assertSame(RiskFactor::PaymentFailures->ceiling(), $payments->points);
    }

    #[Test]
    public function the_score_is_a_percentage_by_construction(): void
    {
        $organization = $this->organization();

        // Everything wrong at once.
        $this->failPayments($organization, 40);
        $this->rejectCampaigns($organization, 20);
        $organization->forceFill(['created_at' => now()])->save();

        $profile = app(RiskAssessor::class)->record($organization->refresh());

        app(\App\Domains\Client\Actions\ReviewRiskProfile::class)
            ->flag($organization, $this->createPlatformUser(), 'Under investigation');

        $this->assertLessThanOrEqual(100, $profile->fresh()->score);
        $this->assertGreaterThanOrEqual(0, $profile->fresh()->score);
    }

    #[Test]
    public function a_new_account_is_not_charged_twice_for_having_no_history(): void
    {
        $organization = $this->organization();
        $organization->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->createVerificationProfile($organization, VerificationStatus::Verified);

        $assessment = app(RiskAssessor::class)->assess($organization->refresh());

        $factors = array_map(fn ($c) => $c->factor, $assessment->contributions);

        // New accounts have no spending baseline. Scoring that as "abnormal
        // spending" as well as "new account" would charge one fact twice.
        $this->assertContains(RiskFactor::NewAccount, $factors);
        $this->assertNotContains(RiskFactor::AbnormalSpending, $factors);
    }

    #[Test]
    public function the_bands_follow_the_specification(): void
    {
        $this->assertSame(RiskLevel::Low, RiskLevel::forScore(0));
        $this->assertSame(RiskLevel::Low, RiskLevel::forScore(30));
        $this->assertSame(RiskLevel::Medium, RiskLevel::forScore(31));
        $this->assertSame(RiskLevel::Medium, RiskLevel::forScore(60));
        $this->assertSame(RiskLevel::High, RiskLevel::forScore(61));
        $this->assertSame(RiskLevel::High, RiskLevel::forScore(80));
        $this->assertSame(RiskLevel::Critical, RiskLevel::forScore(81));
        $this->assertSame(RiskLevel::Critical, RiskLevel::forScore(100));
    }

    #[Test]
    public function the_database_refuses_a_score_and_band_that_disagree(): void
    {
        $organization = $this->organization();

        $this->expectException(\Illuminate\Database\QueryException::class);

        // The queue filters on one and sorts on the other; a row where they
        // disagreed would make it lie.
        DB::table('organization_risk_profiles')->insert([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'tenant_id' => $organization->tenant_id,
            'organization_id' => $organization->getKey(),
            'score' => 95,
            'level' => RiskLevel::Low->value,
            'factors' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function the_database_refuses_an_unattributed_compliance_flag(): void
    {
        $organization = $this->organization();
        app(RiskAssessor::class)->record($organization);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Twenty points nobody can account for.
        DB::table('organization_risk_profiles')
            ->where('organization_id', $organization->getKey())
            ->update(['manual_flag' => true, 'manual_flag_reason' => null]);
    }

    #[Test]
    public function a_compliance_flag_survives_reassessment(): void
    {
        $organization = $this->organization();
        $officer = $this->createPlatformUser();

        app(\App\Domains\Client\Actions\ReviewRiskProfile::class)
            ->flag($organization, $officer, 'Chargeback pattern reported by the acquirer');

        $before = OrganizationRiskProfile::query()
            ->withoutGlobalScopes()
            ->firstWhere('organization_id', $organization->getKey());

        // A scheduled sweep running an hour later must not quietly undo a
        // person's judgement.
        $after = app(RiskAssessor::class)->record($organization);

        $this->assertTrue($after->manual_flag);
        $this->assertSame($before->score, $after->score);

        $flag = collect($after->contributions())
            ->firstWhere(fn ($c): bool => $c->factor === RiskFactor::ComplianceFlag);

        $this->assertNotNull($flag, 'The compliance flag was computed away by the reassessment.');
        $this->assertStringContainsString('Chargeback', $flag->detail);
    }

    #[Test]
    public function clearing_a_flag_keeps_the_record_that_it_existed(): void
    {
        $organization = $this->organization();
        $officer = $this->createPlatformUser();

        $review = app(\App\Domains\Client\Actions\ReviewRiskProfile::class);

        $review->flag($organization, $officer, 'Reported by the acquirer');
        $cleared = $review->clearFlag($organization, $officer, 'Acquirer withdrew the report');

        $this->assertFalse($cleared->manual_flag);

        // Clearing a flag is not the same as it never having existed (§62).
        $this->assertNotNull($cleared->manual_flag_reason);
        $this->assertSame($officer->getKey(), $cleared->manual_flag_by);
    }

    #[Test]
    public function a_change_of_level_puts_a_reviewed_account_back_in_the_queue(): void
    {
        $organization = $this->organization();
        $this->createVerificationProfile($organization, VerificationStatus::Verified);

        $assessor = app(RiskAssessor::class);
        $assessor->record($organization);

        app(\App\Domains\Client\Actions\ReviewRiskProfile::class)
            ->markReviewed($organization, $this->createPlatformUser(), 'Looked at, all fine');

        /*
         * The review answered a particular set of facts. Enough different
         * facts to cross a band, and it no longer applies: failed payments,
         * rejected campaigns and a freshly opened account.
         */
        $this->failPayments($organization, 40);
        $this->rejectCampaigns($organization, 20);
        $organization->forceFill(['created_at' => now()])->save();

        $reassessed = $assessor->record($organization->refresh());

        $this->assertNotSame(RiskLevel::Low, $reassessed->level);
        $this->assertNull($reassessed->reviewed_at);
    }

    private function failPayments(Organization $organization, int $count): void
    {
        $wallet = app(\App\Domains\Wallet\Services\WalletService::class)
            ->walletFor($organization, $organization->default_currency);

        for ($i = 0; $i < $count; $i++) {
            Payment::factory()->create([
                'tenant_id' => $organization->tenant_id,
                'organization_id' => $organization->getKey(),
                'wallet_id' => $wallet->getKey(),
                'status' => PaymentStatus::Failed,
            ]);
        }
    }

    private function rejectCampaigns(Organization $organization, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Campaign::factory()->forOrganization($organization)->create([
                'status' => CampaignStatus::Rejected,
            ]);
        }
    }
}
