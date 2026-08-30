<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Domains\Tenant\Models\Organization;
use App\Domains\Wallet\Enums\LedgerEntryType;
use App\Domains\Wallet\Enums\ReservationStatus;
use App\Domains\Wallet\Enums\WalletStatus;
use App\Domains\Wallet\Exceptions\InsufficientFunds;
use App\Domains\Wallet\Exceptions\InvalidLedgerOperation;
use App\Domains\Wallet\Exceptions\WalletUnavailable;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Services\WalletService;
use App\Support\Exceptions\CurrencyMismatch;
use App\Support\Values\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The ledger and wallet arithmetic (spec §31, §32).
 */
final class LedgerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_deposit_credits_the_wallet_and_the_cache_matches_the_ledger(): void
    {
        $wallet = $this->wallet();

        app(WalletService::class)->deposit($wallet, Money::of('1000.00', 'BDT'), 'Opening balance');

        $wallet->refresh();

        $this->assertSame('1000.00', $wallet->availableBalance()->toDecimal());
        $this->assertTrue($wallet->isReconciled());
    }

    #[Test]
    public function a_wallet_cannot_be_overdrawn(): void
    {
        $wallet = $this->fundedWallet('100.00');

        $this->expectException(InsufficientFunds::class);

        app(WalletService::class)->debit(
            $wallet,
            Money::of('100.01', 'BDT'),
            LedgerEntryType::CampaignSpend,
            'Too much',
        );
    }

    #[Test]
    public function a_reservation_moves_funds_from_available_to_reserved(): void
    {
        $wallet = $this->fundedWallet('1000.00');
        $organization = $wallet->organization()->first();

        app(WalletService::class)->reserve($wallet, Money::of('400.00', 'BDT'), $organization);

        $wallet->refresh();

        $this->assertSame('600.00', $wallet->availableBalance()->toDecimal());
        $this->assertSame('400.00', $wallet->reservedBalance()->toDecimal());
        // The client still holds the money; they just cannot commit it twice.
        $this->assertSame('1000.00', $wallet->totalBalance()->toDecimal());
    }

    #[Test]
    public function capturing_spend_draws_from_the_hold_and_leaves_available_untouched(): void
    {
        $wallet = $this->fundedWallet('1000.00');
        $organization = $wallet->organization()->first();

        $reservation = app(WalletService::class)
            ->reserve($wallet, Money::of('400.00', 'BDT'), $organization);

        app(WalletService::class)->capture($reservation, Money::of('250.00', 'BDT'), 'Campaign spend');

        $wallet->refresh();

        $this->assertSame('600.00', $wallet->availableBalance()->toDecimal());
        $this->assertSame('150.00', $wallet->reservedBalance()->toDecimal());
        $this->assertSame('750.00', $wallet->totalBalance()->toDecimal());
        $this->assertTrue($wallet->isReconciled());
    }

    #[Test]
    public function releasing_returns_the_unspent_hold_to_available(): void
    {
        $wallet = $this->fundedWallet('1000.00');
        $organization = $wallet->organization()->first();

        $reservation = app(WalletService::class)
            ->reserve($wallet, Money::of('400.00', 'BDT'), $organization);

        app(WalletService::class)->capture($reservation, Money::of('250.00', 'BDT'), 'Campaign spend');
        app(WalletService::class)->release($reservation);

        $wallet->refresh();
        $reservation->refresh();

        $this->assertSame('750.00', $wallet->availableBalance()->toDecimal());
        $this->assertSame('0.00', $wallet->reservedBalance()->toDecimal());
        $this->assertSame(ReservationStatus::Captured, $reservation->status);
        $this->assertTrue($wallet->isReconciled());
    }

    #[Test]
    public function releasing_an_already_closed_reservation_is_a_no_op(): void
    {
        $wallet = $this->fundedWallet('1000.00');
        $organization = $wallet->organization()->first();

        $reservation = app(WalletService::class)
            ->reserve($wallet, Money::of('400.00', 'BDT'), $organization);

        app(WalletService::class)->release($reservation);
        $balanceAfterFirst = $wallet->refresh()->available_balance_cached;

        // A retried job must not return the money a second time.
        app(WalletService::class)->release($reservation);

        $this->assertSame($balanceAfterFirst, $wallet->refresh()->available_balance_cached);
    }

    #[Test]
    public function a_hold_cannot_exceed_the_available_balance(): void
    {
        $wallet = $this->fundedWallet('100.00');

        $this->expectException(InsufficientFunds::class);

        app(WalletService::class)->reserve(
            $wallet,
            Money::of('200.00', 'BDT'),
            $wallet->organization()->first(),
        );
    }

    #[Test]
    public function drawing_more_than_a_hold_contains_is_refused(): void
    {
        $wallet = $this->fundedWallet('1000.00');
        $reservation = app(WalletService::class)
            ->reserve($wallet, Money::of('100.00', 'BDT'), $wallet->organization()->first());

        $this->expectException(InvalidLedgerOperation::class);

        app(WalletService::class)->capture($reservation, Money::of('200.00', 'BDT'), 'Too much');
    }

    #[Test]
    public function a_batch_of_movements_is_all_or_nothing(): void
    {
        $wallet = $this->fundedWallet('100.00');

        try {
            app(WalletService::class)->postGroup($wallet, [
                \App\Domains\Wallet\DTOs\LedgerMovement::debit(
                    LedgerEntryType::CampaignSpend, Money::of('60.00', 'BDT'), 'Spend',
                ),
                // The fee tips the batch past the balance, so neither is written.
                \App\Domains\Wallet\DTOs\LedgerMovement::debit(
                    LedgerEntryType::ServiceFee, Money::of('60.00', 'BDT'), 'Fee',
                ),
            ]);
            $this->fail('The batch should have been refused.');
        } catch (InsufficientFunds) {
            // expected
        }

        $wallet->refresh();

        $this->assertSame('100.00', $wallet->availableBalance()->toDecimal());
        $this->assertSame(1, $wallet->entries()->count(), 'Only the opening deposit should exist.');
    }

    #[Test]
    public function related_movements_share_a_transaction_group(): void
    {
        $wallet = $this->fundedWallet('1000.00');

        $entries = app(WalletService::class)->postGroup($wallet, [
            \App\Domains\Wallet\DTOs\LedgerMovement::debit(
                LedgerEntryType::CampaignSpend, Money::of('100.00', 'BDT'), 'Spend',
            ),
            \App\Domains\Wallet\DTOs\LedgerMovement::debit(
                LedgerEntryType::ServiceFee, Money::of('7.50', 'BDT'), 'Platform fee',
            ),
        ]);

        $this->assertCount(2, $entries);
        $this->assertSame(
            $entries[0]->transaction_group_id,
            $entries[1]->transaction_group_id,
            'A spend and its fee are one business event.',
        );
    }

    #[Test]
    public function a_ledger_entry_is_immutable(): void
    {
        $wallet = $this->fundedWallet('100.00');
        $entry = $wallet->entries()->firstOrFail();

        $this->expectException(RuntimeException::class);

        $entry->update(['debit' => 1]);
    }

    #[Test]
    public function a_ledger_entry_cannot_be_deleted(): void
    {
        $wallet = $this->fundedWallet('100.00');
        $entry = $wallet->entries()->firstOrFail();

        $this->expectException(RuntimeException::class);

        $entry->delete();
    }

    #[Test]
    public function a_reversal_undoes_an_entry_without_erasing_it(): void
    {
        $wallet = $this->fundedWallet('1000.00');

        $fee = app(WalletService::class)->debit(
            $wallet,
            Money::of('100.00', 'BDT'),
            LedgerEntryType::ServiceFee,
            'Fee charged in error',
        );

        app(WalletService::class)->reverse($fee, 'Charged in error');

        $wallet->refresh();

        $this->assertSame('1000.00', $wallet->availableBalance()->toDecimal());
        // Three entries: the deposit, the fee, and the reversal. The mistake
        // remains visible (spec §62).
        $this->assertSame(3, $wallet->entries()->count());
        $this->assertTrue($wallet->isReconciled());
    }

    #[Test]
    public function an_entry_cannot_be_reversed_twice(): void
    {
        $wallet = $this->fundedWallet('1000.00');
        $fee = app(WalletService::class)->debit(
            $wallet, Money::of('100.00', 'BDT'), LedgerEntryType::ServiceFee, 'Fee',
        );

        app(WalletService::class)->reverse($fee, 'Charged in error');

        $this->expectException(InvalidLedgerOperation::class);

        app(WalletService::class)->reverse($fee, 'Again');
    }

    #[Test]
    public function an_amount_in_the_wrong_currency_is_refused(): void
    {
        $wallet = $this->fundedWallet('1000.00');

        // Currencies are never converted implicitly (spec §35).
        $this->expectException(CurrencyMismatch::class);

        app(WalletService::class)->deposit($wallet, Money::of('100.00', 'USD'), 'Wrong currency');
    }

    #[Test]
    public function a_frozen_wallet_accepts_deposits_but_refuses_debits(): void
    {
        $wallet = $this->fundedWallet('1000.00');
        $wallet->forceFill(['status' => WalletStatus::Frozen])->save();

        // Blocking an inbound payment would leave the client's money in limbo.
        app(WalletService::class)->deposit($wallet, Money::of('100.00', 'BDT'), 'Deposit while frozen');
        $this->assertSame('1100.00', $wallet->refresh()->availableBalance()->toDecimal());

        $this->expectException(WalletUnavailable::class);

        app(WalletService::class)->debit(
            $wallet, Money::of('10.00', 'BDT'), LedgerEntryType::CampaignSpend, 'Spend',
        );
    }

    #[Test]
    public function a_zero_or_negative_movement_is_refused(): void
    {
        $wallet = $this->fundedWallet('1000.00');

        $this->expectException(InvalidLedgerOperation::class);

        app(WalletService::class)->deposit($wallet, Money::of('0.00', 'BDT'), 'Nothing');
    }

    #[Test]
    public function a_brand_new_wallet_reports_a_zero_balance(): void
    {
        // Every client has an empty wallet before their first deposit, so this
        // is the most common state a wallet is ever read in.
        $wallet = $this->wallet();

        $this->assertSame('0.00', $wallet->availableBalance()->toDecimal());
        $this->assertSame('0.00', $wallet->reservedBalance()->toDecimal());
        $this->assertSame('0.00', $wallet->totalBalance()->toDecimal());
        $this->assertTrue($wallet->isReconciled());
    }

    #[Test]
    public function one_wallet_per_organization_and_currency(): void
    {
        $organization = Organization::factory()->create(['default_currency' => 'BDT']);

        $first = app(WalletService::class)->walletFor($organization);
        $second = app(WalletService::class)->walletFor($organization);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Wallet::acrossTenants()->count());
    }

    private function wallet(): Wallet
    {
        $organization = Organization::factory()->create(['default_currency' => 'BDT']);

        return app(WalletService::class)->walletFor($organization);
    }

    private function fundedWallet(string $amount): Wallet
    {
        $wallet = $this->wallet();

        app(WalletService::class)->deposit($wallet, Money::of($amount, 'BDT'), 'Opening balance');

        return $wallet->refresh();
    }
}
