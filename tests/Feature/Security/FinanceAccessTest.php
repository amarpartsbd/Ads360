<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Payment\Enums\PaymentStatus;
use App\Domains\Payment\Models\Payment;
use App\Domains\Tenant\Services\TenantContext;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Services\WalletService;
use App\Support\Values\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * Financial authorization and tenant isolation (spec §68).
 *
 * The failures that would matter most: one client reaching another's money, and
 * a client confirming their own deposit into the ledger.
 */
final class FinanceAccessTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        $this->seedAccessControl();
    }

    // ------------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------------

    #[Test]
    public function the_global_scope_hides_another_tenants_wallet(): void
    {
        $alpha = $this->createWorkspace();
        $beta = $this->createWorkspace();

        $alphaWallet = app(WalletService::class)->walletFor($alpha['organization']);
        $betaWallet = app(WalletService::class)->walletFor($beta['organization']);

        app(TenantContext::class)->for($alpha['tenant'], $alpha['organization']);

        $this->assertNotNull(Wallet::query()->find($alphaWallet->getKey()));
        $this->assertNull(
            Wallet::query()->find($betaWallet->getKey()),
            'A tenant-scoped query reached another tenant\'s wallet.'
        );
    }

    #[Test]
    public function a_client_cannot_view_another_tenants_wallet(): void
    {
        $alpha = $this->createWorkspace('client-owner');
        $beta = $this->createWorkspace('client-owner');

        $betaWallet = app(WalletService::class)->walletFor($beta['organization']);

        app(TenantContext::class)->for($alpha['tenant'], $alpha['organization']);

        $this->assertFalse($alpha['user']->can('view', $betaWallet));
    }

    #[Test]
    public function ledger_entries_do_not_leak_across_tenants(): void
    {
        $alpha = $this->createWorkspace();
        $beta = $this->createWorkspace();

        $alphaWallet = app(WalletService::class)->walletFor($alpha['organization']);
        $betaWallet = app(WalletService::class)->walletFor($beta['organization']);

        app(WalletService::class)->deposit($alphaWallet, Money::of('100.00', 'BDT'), 'Alpha');
        app(WalletService::class)->deposit($betaWallet, Money::of('999.00', 'BDT'), 'Beta');

        app(TenantContext::class)->for($alpha['tenant'], $alpha['organization']);

        $entries = \App\Domains\Wallet\Models\LedgerEntry::query()->get();

        $this->assertCount(1, $entries);
        $this->assertSame('Alpha', $entries->first()->description);
    }

    #[Test]
    public function a_client_cannot_reach_another_tenants_payment_proof(): void
    {
        $alpha = $this->createWorkspace('client-owner');
        $beta = $this->createWorkspace('client-owner');

        $betaWallet = app(WalletService::class)->walletFor($beta['organization']);
        $betaPayment = Payment::factory()->forWallet($betaWallet)->create([
            'proof_disk' => 'documents',
            'proof_path' => 'fake/path.pdf',
            'proof_filename' => 'slip.pdf',
        ]);

        $this->actingAs($alpha['user'])
            ->get(route('client.wallet.payments.proof', $betaPayment->public_id))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Separation of duties
    // ------------------------------------------------------------------

    #[Test]
    public function no_client_role_can_verify_its_own_deposit(): void
    {
        foreach (['client-owner', 'client-admin', 'client-accountant'] as $roleSlug) {
            $workspace = $this->createWorkspace($roleSlug);
            app(TenantContext::class)->for($workspace['tenant'], $workspace['organization']);

            $wallet = app(WalletService::class)->walletFor($workspace['organization']);
            $payment = Payment::factory()->forWallet($wallet)->create();

            $this->assertFalse(
                $workspace['user']->can('verify', $payment),
                "[{$roleSlug}] could verify its own deposit."
            );
            $this->assertFalse(
                $workspace['user']->hasPermissionTo('payments.verify', $workspace['organization']),
            );
        }
    }

    #[Test]
    public function no_client_role_can_adjust_or_refund_a_wallet(): void
    {
        foreach (['client-owner', 'client-admin', 'client-accountant', 'agency-owner'] as $roleSlug) {
            $workspace = $this->createWorkspace($roleSlug);
            app(TenantContext::class)->for($workspace['tenant'], $workspace['organization']);

            $wallet = app(WalletService::class)->walletFor($workspace['organization']);

            $this->assertFalse(
                $workspace['user']->can('adjust', $wallet),
                "[{$roleSlug}] could adjust a wallet balance."
            );
            $this->assertFalse($workspace['user']->can('refund', $wallet));
        }
    }

    #[Test]
    public function posting_a_verification_as_a_client_is_refused(): void
    {
        $workspace = $this->createWorkspace('client-owner');
        $wallet = app(WalletService::class)->walletFor($workspace['organization']);
        $payment = Payment::factory()->forWallet($wallet)->create();

        $this->actingAs($workspace['user'])
            ->post(route('admin.finance.deposits.verify', $payment->public_id))
            ->assertForbidden();

        $this->assertSame(PaymentStatus::AwaitingVerification, $payment->fresh()?->status);
        $this->assertSame(0, $wallet->refresh()->available_balance_cached);
    }

    #[Test]
    public function an_operations_admin_cannot_touch_money(): void
    {
        // Operations staff run campaigns; they hold no financial permissions.
        $admin = $this->createPlatformUser('operations-admin');
        $workspace = $this->createWorkspace();
        $wallet = app(WalletService::class)->walletFor($workspace['organization']);

        $this->assertFalse($admin->hasPermissionTo('wallet.adjust'));
        $this->assertFalse($admin->hasPermissionTo('payments.verify'));
        $this->assertFalse($admin->can('adjust', $wallet));

        $this->actingAs($admin)
            ->get(route('admin.finance.deposits.index'))
            ->assertForbidden();
    }

    #[Test]
    public function a_finance_admin_can_verify_and_credit(): void
    {
        $workspace = $this->createWorkspace();
        $wallet = app(WalletService::class)->walletFor($workspace['organization']);
        $payment = Payment::factory()->forWallet($wallet)->amount(25_000_00)->create();

        $finance = $this->createPlatformUser('finance-admin');

        $this->actingAs($finance)
            ->post(route('admin.finance.deposits.verify', $payment->public_id), ['note' => 'Confirmed'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(PaymentStatus::Verified, $payment->fresh()?->status);
        $this->assertSame(25_000_00, $wallet->refresh()->available_balance_cached);
    }

    #[Test]
    public function a_client_cannot_reach_the_admin_finance_screens(): void
    {
        $workspace = $this->createWorkspace('client-owner');

        foreach ([
            'admin.finance.deposits.index',
            'admin.finance.wallets.index',
            'admin.finance.approvals.index',
            'admin.finance.exchange-rates.index',
            'admin.finance.pricing.index',
        ] as $routeName) {
            $this->actingAs($workspace['user'])
                ->get(route($routeName))
                ->assertForbidden();
        }
    }

    #[Test]
    public function a_client_sees_only_their_own_wallet_on_their_own_page(): void
    {
        $alpha = $this->createWorkspace('client-owner');
        $beta = $this->createWorkspace('client-owner');

        $alphaWallet = app(WalletService::class)->walletFor($alpha['organization']);
        app(WalletService::class)->deposit($alphaWallet, Money::of('123.00', 'BDT'), 'Alpha funds');

        $betaWallet = app(WalletService::class)->walletFor($beta['organization']);
        app(WalletService::class)->deposit($betaWallet, Money::of('999.00', 'BDT'), 'Beta funds');

        $this->actingAs($alpha['user'])
            ->get(route('client.wallet.overview'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('wallet.available.decimal', '123.00'))
            ->assertDontSee('999.00');
    }

    #[Test]
    public function a_wallet_is_never_serialised_with_another_tenants_figures(): void
    {
        $alpha = $this->createWorkspace('client-owner');
        $beta = $this->createWorkspace('client-owner');

        app(WalletService::class)->deposit(
            app(WalletService::class)->walletFor($beta['organization']),
            Money::of('55555.00', 'BDT'),
            'Beta funds',
        );

        $response = $this->actingAs($alpha['user'])->get(route('client.wallet.transactions'));

        $this->assertStringNotContainsString('55,555.00', $response->getContent() ?: '');
    }
}
