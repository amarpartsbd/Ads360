<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Payment\Actions\SubmitManualDeposit;
use App\Domains\Payment\Actions\VerifyPayment;
use App\Domains\Payment\Enums\PaymentMethod;
use App\Domains\Payment\Enums\PaymentStatus;
use App\Domains\Payment\Exceptions\InvalidPaymentTransition;
use App\Domains\Payment\Models\Payment;
use App\Domains\Tenant\Services\TenantContext;
use App\Domains\Wallet\Models\LedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Support\Values\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantWorkspaces;
use Tests\TestCase;

/**
 * Manual deposits and payment idempotency (spec §30, §34).
 */
final class DepositTest extends TestCase
{
    use CreatesTenantWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Notification::fake();
        $this->seedAccessControl();
    }

    #[Test]
    public function submitting_a_deposit_credits_nothing_until_it_is_verified(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $payment = $this->submit($organization, $user, '25000.00');

        $this->assertSame(PaymentStatus::AwaitingVerification, $payment->status);

        // Crediting on a client's say-so would let anyone mint balance.
        $wallet = app(WalletService::class)->walletFor($organization);
        $this->assertSame(0, $wallet->available_balance_cached);
        $this->assertSame(0, $wallet->entries()->count());
    }

    #[Test]
    public function verifying_credits_the_wallet_exactly_once(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $payment = $this->submit($organization, $user, '25000.00');
        $finance = $this->createPlatformUser('finance-admin');

        app(VerifyPayment::class)->handle($payment, $finance, 'Matches the bank statement');

        $wallet = app(WalletService::class)->walletFor($organization)->refresh();

        $this->assertSame(25_000_00, $wallet->available_balance_cached);
        $this->assertSame(1, $wallet->entries()->count());
        $this->assertNotNull($payment->fresh()?->ledger_entry_id);
    }

    #[Test]
    public function verifying_twice_is_a_no_op(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $payment = $this->submit($organization, $user, '25000.00');
        $finance = $this->createPlatformUser('finance-admin');

        app(VerifyPayment::class)->handle($payment, $finance);
        app(VerifyPayment::class)->handle($payment->fresh(), $finance);

        $wallet = app(WalletService::class)->walletFor($organization)->refresh();

        $this->assertSame(25_000_00, $wallet->available_balance_cached, 'The deposit was credited twice.');
        $this->assertSame(1, $wallet->entries()->count());
    }

    #[Test]
    public function the_database_refuses_a_second_deposit_entry_for_one_payment(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $payment = $this->submit($organization, $user, '25000.00');
        $finance = $this->createPlatformUser('finance-admin');

        app(VerifyPayment::class)->handle($payment, $finance);

        // Bypassing every application guard on purpose: the unique index is
        // the last line of defence and must hold on its own (spec §30).
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        app(WalletService::class)->deposit(
            app(WalletService::class)->walletFor($organization),
            Money::of('25000.00', 'BDT'),
            'Duplicate credit',
            $payment->fresh(),
        );
    }

    #[Test]
    public function a_retried_submission_with_the_same_key_returns_the_original(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $first = $this->submit($organization, $user, '25000.00', key: 'retry-key');
        $second = $this->submit($organization, $user, '25000.00', key: 'retry-key');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Payment::acrossTenants()->count());
    }

    #[Test]
    public function a_rejected_deposit_credits_nothing(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $payment = $this->submit($organization, $user, '25000.00');
        $finance = $this->createPlatformUser('finance-admin');

        app(VerifyPayment::class)->reject($payment, $finance, 'No matching transfer found');

        $payment->refresh();

        $this->assertSame(PaymentStatus::Rejected, $payment->status);
        $this->assertNull($payment->ledger_entry_id);
        $this->assertSame(
            0,
            app(WalletService::class)->walletFor($organization)->refresh()->available_balance_cached,
        );
    }

    #[Test]
    public function a_rejected_deposit_cannot_then_be_verified(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $payment = $this->submit($organization, $user, '25000.00');
        $finance = $this->createPlatformUser('finance-admin');

        app(VerifyPayment::class)->reject($payment, $finance, 'No matching transfer');

        $this->expectException(InvalidPaymentTransition::class);

        app(VerifyPayment::class)->handle($payment->fresh(), $finance);
    }

    #[Test]
    public function a_deposit_below_the_minimum_is_refused(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $this->expectException(ValidationException::class);

        $this->submit($organization, $user, '1.00');
    }

    #[Test]
    public function a_manual_deposit_requires_proof(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $this->expectException(ValidationException::class);

        app(SubmitManualDeposit::class)->handle(
            organization: $organization,
            submitter: $user,
            amount: Money::of('25000.00', 'BDT'),
            method: PaymentMethod::BankTransfer,
            externalReference: 'TXN-1',
            proof: null,
        );
    }

    #[Test]
    public function submitting_and_verifying_are_both_audited(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $payment = $this->submit($organization, $user, '25000.00');
        $finance = $this->createPlatformUser('finance-admin');

        app(VerifyPayment::class)->handle($payment, $finance);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::DepositSubmitted->value,
            'actor_id' => $user->getKey(),
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::DepositApproved->value,
            'actor_id' => $finance->getKey(),
        ]);
    }

    #[Test]
    public function a_client_submits_a_deposit_over_http(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $this->actingAs($user)
            ->post(route('client.wallet.deposits.store'), [
                'amount' => '25000.00',
                'method' => PaymentMethod::BankTransfer->value,
                'external_reference' => 'TXN-987654',
                'proof' => UploadedFile::fake()->createWithContent('slip.pdf', '%PDF-1.4 slip'),
            ])
            ->assertRedirect(route('client.wallet.overview'))
            ->assertSessionHasNoErrors();

        $payment = Payment::acrossTenants()->firstOrFail();

        $this->assertSame(25_000_00, $payment->amount);
        $this->assertTrue($payment->hasProof());
        $this->assertSame(PaymentStatus::AwaitingVerification, $payment->status);
    }

    #[Test]
    public function a_double_clicked_submission_creates_one_deposit(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $payload = [
            'amount' => '25000.00',
            'method' => PaymentMethod::BankTransfer->value,
            'external_reference' => 'TXN-987654',
        ];

        // The idempotency key is derived from the submission itself, so an
        // impatient second click cannot create a second claim (spec §30).
        foreach ([1, 2] as $ignored) {
            $this->actingAs($user)->post(route('client.wallet.deposits.store'), [
                ...$payload,
                'proof' => UploadedFile::fake()->createWithContent('slip.pdf', '%PDF-1.4 slip'),
            ]);
        }

        $this->assertSame(1, Payment::acrossTenants()->count());
    }

    #[Test]
    public function a_verified_deposit_records_the_ledger_entry_it_produced(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->workspace();

        $payment = $this->submit($organization, $user, '25000.00');
        app(VerifyPayment::class)->handle($payment, $this->createPlatformUser('finance-admin'));

        $entry = LedgerEntry::acrossTenants()->firstOrFail();

        $this->assertSame(Payment::class, $entry->reference_type);
        $this->assertSame((string) $payment->getKey(), $entry->reference_id);
        $this->assertSame($entry->getKey(), $payment->fresh()?->ledger_entry_id);
    }

    /**
     * @return array{tenant: \App\Domains\Tenant\Models\Tenant, organization: \App\Domains\Tenant\Models\Organization, user: \App\Domains\Identity\Models\User}
     */
    private function workspace(): array
    {
        $workspace = $this->createWorkspace('client-owner');

        app(TenantContext::class)->for($workspace['tenant'], $workspace['organization']);

        return $workspace;
    }

    private function submit(
        \App\Domains\Tenant\Models\Organization $organization,
        \App\Domains\Identity\Models\User $user,
        string $amount,
        ?string $key = null,
    ): Payment {
        return app(SubmitManualDeposit::class)->handle(
            organization: $organization,
            submitter: $user,
            amount: Money::of($amount, 'BDT'),
            method: PaymentMethod::BankTransfer,
            externalReference: 'TXN-'.substr(md5($amount), 0, 8),
            proof: UploadedFile::fake()->createWithContent('slip.pdf', '%PDF-1.4 slip'),
            idempotencyKey: $key,
        );
    }
}
