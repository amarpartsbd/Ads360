<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use App\Domains\System\Enums\ApprovalStatus;
use App\Domains\System\Models\ApprovalRequest;
use App\Domains\System\Services\ApprovalService;
use App\Domains\Tenant\Services\TenantContext;
use App\Domains\Wallet\Actions\AdjustWallet;
use App\Domains\Wallet\Actions\RefundToClient;
use App\Domains\Wallet\Models\LedgerEntry;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Services\WalletService;
use App\Support\Values\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * Maker-checker on high-value financial actions (spec §25).
 *
 * The control only means something if the person who asked for an action cannot
 * be the one who signs it off, and if approving actually executes what was
 * approved rather than leaving it to be done by hand.
 */
final class MakerCheckerTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    #[Test]
    public function a_small_adjustment_executes_immediately(): void
    {
        [$wallet, $maker] = $this->setUpWallet();

        $result = app(AdjustWallet::class)->handle(
            $wallet, Money::of('100.00', 'BDT'), true, 'Small correction', $maker,
        );

        $this->assertInstanceOf(LedgerEntry::class, $result);
        $this->assertSame(10000, $wallet->refresh()->available_balance_cached);
    }

    #[Test]
    public function a_large_adjustment_waits_for_approval(): void
    {
        [$wallet, $maker] = $this->setUpWallet();

        $result = app(AdjustWallet::class)->handle(
            $wallet, Money::of('100000.00', 'BDT'), true, 'Goodwill credit', $maker,
        );

        $this->assertInstanceOf(ApprovalRequest::class, $result);
        $this->assertSame(ApprovalStatus::Pending, $result->status);

        // Nothing has moved yet.
        $this->assertSame(0, $wallet->refresh()->available_balance_cached);
    }

    #[Test]
    public function the_requester_cannot_approve_their_own_request(): void
    {
        [$wallet, $maker] = $this->setUpWallet();

        $request = app(AdjustWallet::class)->handle(
            $wallet, Money::of('100000.00', 'BDT'), true, 'Goodwill credit', $maker,
        );

        $this->assertFalse($request->canBeDecidedBy($maker));
        $this->assertFalse($maker->can('decide', $request));

        $this->expectException(ValidationException::class);

        app(ApprovalService::class)->approve($request, $maker);
    }

    #[Test]
    public function approval_by_a_second_person_executes_the_recorded_action(): void
    {
        [$wallet, $maker] = $this->setUpWallet();
        $checker = $this->createPlatformUser('finance-admin');

        $request = app(AdjustWallet::class)->handle(
            $wallet, Money::of('100000.00', 'BDT'), true, 'Goodwill credit', $maker,
        );

        $this->actingAs($checker)
            ->post(route('admin.finance.approvals.approve', $request->public_id), ['note' => 'Agreed'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $request->refresh();

        $this->assertSame(ApprovalStatus::Executed, $request->status);
        // Executed from the recorded payload, not from anything resubmitted.
        $this->assertSame(100_000_00, $wallet->refresh()->available_balance_cached);
    }

    #[Test]
    public function a_rejected_request_never_executes(): void
    {
        [$wallet, $maker] = $this->setUpWallet();
        $checker = $this->createPlatformUser('finance-admin');

        $request = app(AdjustWallet::class)->handle(
            $wallet, Money::of('100000.00', 'BDT'), true, 'Goodwill credit', $maker,
        );

        app(ApprovalService::class)->reject($request, $checker, 'Not justified');

        $this->assertSame(ApprovalStatus::Rejected, $request->fresh()?->status);
        $this->assertSame(0, $wallet->refresh()->available_balance_cached);
    }

    #[Test]
    public function nobody_may_vote_on_the_same_request_twice(): void
    {
        [$wallet, $maker] = $this->setUpWallet();
        $checker = $this->createPlatformUser('finance-admin');

        // Two approvals are needed at ten times the threshold, so the first
        // vote does not resolve it.
        $request = app(AdjustWallet::class)->handle(
            $wallet, Money::of('1000000.00', 'BDT'), true, 'Very large credit', $maker,
        );

        $this->assertSame(2, $request->required_approvals);

        app(ApprovalService::class)->approve($request, $checker);

        $this->expectException(ValidationException::class);

        // Clicking twice must not satisfy a two-approver threshold.
        app(ApprovalService::class)->approve($request->fresh(), $checker);
    }

    #[Test]
    public function two_distinct_approvers_satisfy_a_two_approval_threshold(): void
    {
        [$wallet, $maker] = $this->setUpWallet();
        $first = $this->createPlatformUser('finance-admin');
        $second = $this->createPlatformUser('super-admin');

        $request = app(AdjustWallet::class)->handle(
            $wallet, Money::of('1000000.00', 'BDT'), true, 'Very large credit', $maker,
        );

        $afterFirst = app(ApprovalService::class)->approve($request, $first);
        $this->assertSame(ApprovalStatus::Pending, $afterFirst->status);

        $afterSecond = app(ApprovalService::class)->approve($afterFirst, $second);
        $this->assertSame(ApprovalStatus::Approved, $afterSecond->status);
    }

    #[Test]
    public function an_approver_without_the_matching_permission_is_refused(): void
    {
        [$wallet, $maker] = $this->setUpWallet();

        // A support agent can see nothing financial, let alone sign it off.
        $agent = $this->createPlatformUser('support-agent');

        $request = app(AdjustWallet::class)->handle(
            $wallet, Money::of('100000.00', 'BDT'), true, 'Goodwill credit', $maker,
        );

        $this->assertFalse($agent->can('decide', $request));

        $this->expectException(ValidationException::class);

        app(ApprovalService::class)->approve($request, $agent);
    }

    #[Test]
    public function a_large_refund_also_needs_approval(): void
    {
        [$wallet, $maker] = $this->setUpWallet();

        app(WalletService::class)->deposit($wallet, Money::of('200000.00', 'BDT'), 'Funds');

        $result = app(RefundToClient::class)->handle(
            $wallet, Money::of('100000.00', 'BDT'), 'Client closing account', $maker,
        );

        $this->assertInstanceOf(ApprovalRequest::class, $result);
        $this->assertSame(200_000_00, $wallet->refresh()->available_balance_cached);
    }

    #[Test]
    public function an_approved_refund_debits_the_wallet_when_executed(): void
    {
        [$wallet, $maker] = $this->setUpWallet();
        $checker = $this->createPlatformUser('finance-admin');

        app(WalletService::class)->deposit($wallet, Money::of('200000.00', 'BDT'), 'Funds');

        $request = app(RefundToClient::class)->handle(
            $wallet, Money::of('100000.00', 'BDT'), 'Client closing account', $maker,
        );

        $this->actingAs($checker)
            ->post(route('admin.finance.approvals.approve', $request->public_id))
            ->assertSessionHasNoErrors();

        $this->assertSame(100_000_00, $wallet->refresh()->available_balance_cached);
        $this->assertTrue($wallet->isReconciled());
    }

    #[Test]
    public function an_adjustment_must_record_a_reason(): void
    {
        [$wallet, $maker] = $this->setUpWallet();

        $this->expectException(ValidationException::class);

        app(AdjustWallet::class)->handle($wallet, Money::of('100.00', 'BDT'), true, '   ', $maker);
    }

    #[Test]
    public function a_resolved_request_cannot_be_decided_again(): void
    {
        [$wallet, $maker] = $this->setUpWallet();
        $checker = $this->createPlatformUser('finance-admin');
        $other = $this->createPlatformUser('super-admin');

        $request = app(AdjustWallet::class)->handle(
            $wallet, Money::of('100000.00', 'BDT'), true, 'Goodwill', $maker,
        );

        app(ApprovalService::class)->reject($request, $checker, 'Not justified');

        $this->expectException(ValidationException::class);

        app(ApprovalService::class)->approve($request->fresh(), $other);
    }

    /**
     * @return array{0: Wallet, 1: \App\Domains\Identity\Models\User}
     */
    private function setUpWallet(): array
    {
        $workspace = $this->createWorkspace();
        app(TenantContext::class)->for($workspace['tenant'], $workspace['organization']);

        $wallet = app(WalletService::class)->walletFor($workspace['organization']);
        $maker = $this->createPlatformUser('finance-admin');

        return [$wallet, $maker];
    }
}
