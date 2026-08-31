<?php

declare(strict_types=1);

namespace Tests\Feature\Risk;

use App\Domains\Client\Actions\ReviewRiskProfile;
use App\Domains\Client\Enums\RiskLevel;
use App\Domains\Client\Models\OrganizationRiskProfile;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\System\Enums\ApprovableAction;
use App\Domains\System\Enums\ApprovalStatus;
use App\Domains\System\Models\ApprovalRequest;
use App\Domains\System\Services\ApprovalService;
use App\Domains\Tenant\Enums\OrganizationStatus;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Wallet\Actions\AdjustWallet;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Services\WalletService;
use App\Support\Values\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * What a risk score is allowed to do (spec §12, §25).
 *
 * The negative tests are the important ones. §12 is explicit that a score must
 * never withdraw financial access on its own, and the failure mode this guards
 * against is the tempting one: a scoring change that quietly starts freezing
 * accounts, where the first anyone hears of it is a client who cannot advertise.
 */
final class RiskConsequencesTest extends TestCase
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
        $organization->forceFill(['created_at' => now()->subMonths(6)])->save();

        return $organization->refresh();
    }

    private function walletFor(Organization $organization): Wallet
    {
        return app(WalletService::class)->walletFor($organization, $organization->default_currency);
    }

    /**
     * An account that genuinely reaches the High band: unverified, a
     * compliance flag, failed payments and rejected campaigns.
     *
     * Built from real facts rather than by writing a score directly, so the
     * fixture cannot drift away from what the assessor would actually produce.
     */
    private function makeHighRisk(Organization $organization): OrganizationRiskProfile
    {
        $wallet = $this->walletFor($organization);

        for ($i = 0; $i < 3; $i++) {
            \App\Domains\Payment\Models\Payment::factory()->create([
                'tenant_id' => $organization->tenant_id,
                'organization_id' => $organization->getKey(),
                'wallet_id' => $wallet->getKey(),
                'status' => \App\Domains\Payment\Enums\PaymentStatus::Failed,
            ]);
        }

        for ($i = 0; $i < 3; $i++) {
            \App\Domains\Campaign\Models\Campaign::factory()
                ->forOrganization($organization)
                ->create(['status' => \App\Domains\Campaign\Enums\CampaignStatus::Rejected]);
        }

        $profile = app(ReviewRiskProfile::class)->flag(
            $organization,
            $this->createPlatformUser(),
            'Under investigation by the acquirer',
        );

        $this->assertTrue(
            $profile->level->requiresSecondApprover(),
            "The fixture scored {$profile->score}, which is not high enough to be a high-risk client."
        );

        return $profile;
    }

    #[Test]
    public function a_high_risk_client_never_has_its_account_suspended_by_the_score(): void
    {
        $organization = $this->organization();

        $profile = $this->makeHighRisk($organization);

        $this->assertTrue($profile->level->requiresSecondApprover());

        // The account and the wallet are exactly as they were.
        $this->assertNotSame(OrganizationStatus::Suspended, $organization->refresh()->status);
        $this->assertSame(
            \App\Domains\Wallet\Enums\WalletStatus::Active,
            $this->walletFor($organization)->fresh()->status,
        );
    }

    #[Test]
    public function the_only_automatic_consequence_is_an_extra_approver(): void
    {
        $organization = $this->organization();
        $this->makeHighRisk($organization);

        $approvals = app(ApprovalService::class);

        // Far below the wallet-adjustment threshold, so size alone would not
        // ask for approval at all.
        $small = Money::of('10.00', $organization->default_currency);

        $this->assertFalse(
            $approvals->isRequired(ApprovableAction::WalletAdjustment, $small),
            'The size threshold should not have been met.'
        );

        $this->assertTrue(
            $approvals->isRequired(ApprovableAction::WalletAdjustment, $small, $organization),
            'A financial action on a high-risk client should need a second pair of eyes.'
        );
    }

    #[Test]
    public function a_low_risk_client_is_unaffected(): void
    {
        $organization = $this->organization();
        $this->createVerificationProfile($organization, \App\Domains\Compliance\Enums\VerificationStatus::Verified);

        app(\App\Domains\Client\Services\RiskAssessor::class)->record($organization);

        $small = Money::of('10.00', $organization->default_currency);

        $this->assertFalse(
            app(ApprovalService::class)
                ->isRequired(ApprovableAction::WalletAdjustment, $small, $organization),
        );
    }

    #[Test]
    public function an_organization_with_no_profile_yet_is_not_treated_as_risky(): void
    {
        $organization = $this->organization();

        // A platform that scored the unassessed as dangerous would put every
        // new client behind a second approver on their first day.
        $this->assertFalse(
            app(ApprovalService::class)->isRequired(
                ApprovableAction::WalletAdjustment,
                Money::of('10.00', $organization->default_currency),
                $organization,
            ),
        );
    }

    #[Test]
    public function a_small_adjustment_on_a_risky_client_goes_to_the_queue_with_its_reason(): void
    {
        $organization = $this->organization();
        $this->makeHighRisk($organization);

        $wallet = $this->walletFor($organization);
        $finance = $this->createPlatformUser('finance-admin');

        $result = app(AdjustWallet::class)->handle(
            wallet: $wallet,
            amount: Money::of('10.00', $wallet->currency_code ?? $organization->default_currency),
            isCredit: true,
            reason: 'Goodwill credit',
            actor: $finance,
        );

        $this->assertInstanceOf(ApprovalRequest::class, $result);
        $this->assertSame(2, $result->required_approvals);

        // The approver is told why, because an instruction nobody can evaluate
        // is one people learn to click through.
        $this->assertStringContainsString('risk', (string) $result->elevation_reason);
    }

    #[Test]
    public function the_reason_recorded_is_the_one_that_was_true_when_it_was_raised(): void
    {
        $organization = $this->organization();
        $officer = $this->createPlatformUser();
        $this->makeHighRisk($organization);

        $wallet = $this->walletFor($organization);

        /** @var ApprovalRequest $request */
        $request = app(AdjustWallet::class)->handle(
            wallet: $wallet,
            amount: Money::of('10.00', $organization->default_currency),
            isCredit: true,
            reason: 'Goodwill credit',
            actor: $this->createPlatformUser('finance-admin'),
        );

        // Risk falls afterwards.
        app(ReviewRiskProfile::class)->clearFlag($organization, $officer, 'Acquirer withdrew it');

        $this->assertNotNull($request->fresh()->elevation_reason);
    }

    #[Test]
    public function a_large_movement_needs_a_senior_signature_as_well_as_a_second_one(): void
    {
        $organization = $this->organization();
        $wallet = $this->walletFor($organization);

        $maker = $this->createPlatformUser('finance-admin');
        $checker = $this->createPlatformUser('finance-admin');

        // Ten times the threshold: two approvals, and §25 asks for one of them
        // to be senior.
        $large = Money::ofMinor(
            (int) config('platform.finance.maker_checker.wallet_adjustment_minor') * 10,
            $organization->default_currency,
        );

        /** @var ApprovalRequest $request */
        $request = app(AdjustWallet::class)->handle(
            wallet: $wallet,
            amount: $large,
            isCredit: true,
            reason: 'Settlement correction',
            actor: $maker,
        );

        $this->assertTrue($request->requires_senior_approval);
        $this->assertFalse($checker->hasPermissionTo(Permission::ApprovalsSenior));

        $approvals = app(ApprovalService::class);
        $approvals->approve($request, $checker);

        $second = $this->createPlatformUser('finance-admin');
        $after = $approvals->approve($request->fresh(), $second);

        /*
         * Two finance approvals is a second pair of eyes. It is not a second
         * level of authority, which is what "Finance + Senior Approval" means.
         */
        $this->assertSame(ApprovalStatus::Pending, $after->status);
        $this->assertTrue($after->awaitingSeniorApproval());
        $this->assertStringContainsString('senior', (string) $after->outstandingSummary());
    }

    #[Test]
    public function a_senior_signature_completes_it(): void
    {
        $organization = $this->organization();
        $wallet = $this->walletFor($organization);

        $maker = $this->createPlatformUser('finance-admin');
        $checker = $this->createPlatformUser('finance-admin');
        $senior = $this->createPlatformUser('super-admin');

        $large = Money::ofMinor(
            (int) config('platform.finance.maker_checker.wallet_adjustment_minor') * 10,
            $organization->default_currency,
        );

        /** @var ApprovalRequest $request */
        $request = app(AdjustWallet::class)->handle(
            wallet: $wallet,
            amount: $large,
            isCredit: true,
            reason: 'Settlement correction',
            actor: $maker,
        );

        $approvals = app(ApprovalService::class);
        $approvals->approve($request, $checker);
        $final = $approvals->approve($request->fresh(), $senior);

        $this->assertSame(ApprovalStatus::Approved, $final->status);
        $this->assertNotNull($final->senior_approved_at);
        $this->assertSame($senior->getKey(), $final->senior_approved_by);
    }

    #[Test]
    public function a_senior_approving_first_still_counts(): void
    {
        $organization = $this->organization();
        $wallet = $this->walletFor($organization);

        $maker = $this->createPlatformUser('finance-admin');
        $senior = $this->createPlatformUser('super-admin');
        $checker = $this->createPlatformUser('finance-admin');

        $large = Money::ofMinor(
            (int) config('platform.finance.maker_checker.wallet_adjustment_minor') * 10,
            $organization->default_currency,
        );

        /** @var ApprovalRequest $request */
        $request = app(AdjustWallet::class)->handle(
            wallet: $wallet,
            amount: $large,
            isCredit: true,
            reason: 'Settlement correction',
            actor: $maker,
        );

        $approvals = app(ApprovalService::class);

        // What §25 asks is that a senior person looked, not that they looked
        // last.
        $approvals->approve($request, $senior);
        $final = $approvals->approve($request->fresh(), $checker);

        $this->assertSame(ApprovalStatus::Approved, $final->status);
    }

    #[Test]
    public function an_ordinary_sized_movement_needs_no_senior_signature(): void
    {
        $organization = $this->organization();
        $wallet = $this->walletFor($organization);

        $maker = $this->createPlatformUser('finance-admin');
        $checker = $this->createPlatformUser('finance-admin');

        $ordinary = Money::ofMinor(
            (int) config('platform.finance.maker_checker.wallet_adjustment_minor'),
            $organization->default_currency,
        );

        /** @var ApprovalRequest $request */
        $request = app(AdjustWallet::class)->handle(
            wallet: $wallet,
            amount: $ordinary,
            isCredit: true,
            reason: 'Settlement correction',
            actor: $maker,
        );

        $this->assertFalse($request->requires_senior_approval);

        $final = app(ApprovalService::class)->approve($request, $checker);

        $this->assertSame(ApprovalStatus::Approved, $final->status);
    }

    #[Test]
    public function the_database_refuses_a_senior_signature_nobody_asked_for(): void
    {
        $organization = $this->organization();
        $wallet = $this->walletFor($organization);

        $ordinary = Money::ofMinor(
            (int) config('platform.finance.maker_checker.wallet_adjustment_minor'),
            $organization->default_currency,
        );

        /** @var ApprovalRequest $request */
        $request = app(AdjustWallet::class)->handle(
            wallet: $wallet,
            amount: $ordinary,
            isCredit: true,
            reason: 'Settlement correction',
            actor: $this->createPlatformUser('finance-admin'),
        );

        $this->expectException(\Illuminate\Database\QueryException::class);

        // A senior signature on a request that never required one would make
        // the column unreadable.
        \Illuminate\Support\Facades\DB::table('approval_requests')
            ->where('id', $request->getKey())
            ->update(['senior_approved_at' => now(), 'senior_approved_by' => null]);
    }

    #[Test]
    public function a_client_is_never_shown_their_own_risk_score(): void
    {
        $organization = $this->organization();
        $workspace = $this->createWorkspace();

        /** @var User $client */
        $client = $workspace['user'];

        // §54 names internal risk notes among the things never exposed outside
        // the platform.
        $this->assertFalse($client->can('viewAny', OrganizationRiskProfile::class));

        $this->actingAs($client)
            ->get(route('admin.risk.index'))
            ->assertForbidden();
    }

    #[Test]
    public function reading_risk_does_not_carry_the_right_to_change_it(): void
    {
        $finance = $this->createPlatformUser('finance-admin');

        // Finance decides whether to release funds and so needs to see the
        // score; flagging a client's business is compliance's job.
        $this->assertTrue($finance->can('viewAny', OrganizationRiskProfile::class));
        $this->assertFalse($finance->can('manage', OrganizationRiskProfile::class));
    }

    #[Test]
    public function finance_alone_cannot_satisfy_a_senior_requirement(): void
    {
        $finance = $this->createPlatformUser('finance-admin');

        // "Finance + Senior Approval" is two different kinds of person, and a
        // permission finance already held would make the second signature a
        // formality (spec §25).
        $this->assertTrue($finance->hasPermissionTo(Permission::WalletAdjust));
        $this->assertFalse($finance->hasPermissionTo(Permission::ApprovalsSenior));
    }

    #[Test]
    public function the_bands_that_add_an_approver_are_high_and_critical_only(): void
    {
        $this->assertFalse(RiskLevel::Low->requiresSecondApprover());
        $this->assertFalse(RiskLevel::Medium->requiresSecondApprover());
        $this->assertTrue(RiskLevel::High->requiresSecondApprover());
        $this->assertTrue(RiskLevel::Critical->requiresSecondApprover());
    }
}
