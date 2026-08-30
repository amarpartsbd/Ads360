<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Domains\Tenant\Models\Organization;
use App\Domains\Wallet\Enums\LedgerEntryType;
use App\Domains\Wallet\Exceptions\InsufficientFunds;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletReservation;
use App\Domains\Wallet\Services\WalletService;
use App\Support\Values\Money;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RunsConcurrently;
use Tests\TestCase;

/**
 * Financial concurrency (spec §56, §98).
 *
 * This is the gate on Phase 2: no finance module ships while any of these
 * fail. Each test runs real parallel processes competing for one wallet — the
 * only way to demonstrate that two requests cannot spend the same balance.
 *
 * DatabaseMigrations rather than RefreshDatabase: the latter wraps a test in a
 * transaction, and a forked child cannot see rows the parent has not committed.
 */
#[Group('concurrency')]
final class WalletConcurrencyTest extends TestCase
{
    use DatabaseMigrations;
    use RunsConcurrently;

    #[Test]
    public function two_processes_cannot_spend_the_same_balance(): void
    {
        // Enough for exactly one of the two debits.
        $wallet = $this->walletWith('100.00');
        $walletId = $wallet->getKey();

        $result = $this->runConcurrently(2, function () use ($walletId): bool {
            $wallet = Wallet::query()->withoutGlobalScopes()->findOrFail($walletId);

            try {
                app(WalletService::class)->debit(
                    $wallet,
                    Money::of('100.00', 'BDT'),
                    LedgerEntryType::CampaignSpend,
                    'Concurrent spend',
                );

                return true;
            } catch (InsufficientFunds) {
                return false;
            }
        });

        $this->assertSame([], $result['errors'], 'A worker failed for an unexpected reason.');
        $this->assertSame(1, $result['succeeded'], 'Exactly one debit should have succeeded.');
        $this->assertSame(1, $result['failed'], 'The losing debit should have been refused.');

        $wallet->refresh();
        $this->assertSame(0, $wallet->available_balance_cached);
        $this->assertTrue($wallet->isReconciled(), 'Cached balance drifted from the ledger.');
        $this->assertSame(2, $wallet->entries()->count(), 'One deposit and one spend expected.');
    }

    #[Test]
    public function many_processes_racing_one_balance_never_overdraw_it(): void
    {
        // Ten workers, each wanting 100, against a balance that covers four.
        $wallet = $this->walletWith('400.00');
        $walletId = $wallet->getKey();

        $result = $this->runConcurrently(10, function () use ($walletId): bool {
            $wallet = Wallet::query()->withoutGlobalScopes()->findOrFail($walletId);

            try {
                app(WalletService::class)->debit(
                    $wallet,
                    Money::of('100.00', 'BDT'),
                    LedgerEntryType::CampaignSpend,
                    'Concurrent spend',
                );

                return true;
            } catch (InsufficientFunds) {
                return false;
            }
        });

        $this->assertSame([], $result['errors']);
        $this->assertSame(4, $result['succeeded'], 'Exactly four debits should fit in the balance.');
        $this->assertSame(6, $result['failed']);

        $wallet->refresh();
        $this->assertSame(0, $wallet->available_balance_cached);
        $this->assertTrue($wallet->isReconciled());
    }

    #[Test]
    public function concurrent_reservations_cannot_over_allocate_the_balance(): void
    {
        // Budget holds are the campaign-approval path (spec §32): two approvals
        // landing together must not reserve more than the client holds.
        $wallet = $this->walletWith('300.00');
        $walletId = $wallet->getKey();

        // Each worker holds against a different organization row, so the
        // partial unique index (one open hold per reference) is not what
        // decides the outcome — available balance is.
        $references = Organization::factory()->count(6)->create()->pluck('id')->all();

        $result = $this->runConcurrently(6, function (int $worker) use ($walletId, $references): bool {
            $wallet = Wallet::query()->withoutGlobalScopes()->findOrFail($walletId);
            $reference = Organization::query()->withoutGlobalScopes()->findOrFail($references[$worker]);

            try {
                app(WalletService::class)->reserve($wallet, Money::of('100.00', 'BDT'), $reference);

                return true;
            } catch (InsufficientFunds) {
                return false;
            }
        });

        $this->assertSame([], $result['errors']);
        $this->assertSame(3, $result['succeeded']);

        $wallet->refresh();
        $this->assertSame(0, $wallet->available_balance_cached);
        $this->assertSame(30000, $wallet->reserved_balance_cached);
        $this->assertTrue($wallet->isReconciled());
    }

    #[Test]
    public function concurrent_deposits_are_all_recorded_exactly_once(): void
    {
        // The opposite risk: a lost update, where two credits read the same
        // balance and one overwrites the other.
        $wallet = $this->walletWith('0.00', deposit: false);
        $walletId = $wallet->getKey();

        $result = $this->runConcurrently(8, function () use ($walletId): bool {
            $wallet = Wallet::query()->withoutGlobalScopes()->findOrFail($walletId);

            app(WalletService::class)->deposit(
                $wallet,
                Money::of('50.00', 'BDT'),
                'Concurrent deposit',
            );

            return true;
        });

        $this->assertSame([], $result['errors']);
        $this->assertSame(8, $result['succeeded']);

        $wallet->refresh();
        $this->assertSame(40000, $wallet->available_balance_cached, 'A deposit was lost.');
        $this->assertSame(8, $wallet->entries()->count());
        $this->assertTrue($wallet->isReconciled());
    }

    #[Test]
    public function concurrent_captures_cannot_draw_more_than_the_hold(): void
    {
        $wallet = $this->walletWith('500.00');
        $organization = Organization::query()->withoutGlobalScopes()->findOrFail($wallet->organization_id);

        $reservation = app(WalletService::class)->reserve(
            $wallet,
            Money::of('200.00', 'BDT'),
            $organization,
        );

        $reservationId = $reservation->getKey();

        // Five workers each drawing 100 against a hold of 200.
        $result = $this->runConcurrently(5, function () use ($reservationId): bool {
            $reservation = WalletReservation::query()
                ->withoutGlobalScopes()
                ->findOrFail($reservationId);

            try {
                app(WalletService::class)->capture(
                    $reservation,
                    Money::of('100.00', 'BDT'),
                    'Concurrent capture',
                );

                return true;
            } catch (\App\Domains\Wallet\Exceptions\InvalidLedgerOperation) {
                return false;
            }
        });

        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['succeeded'], 'Only two captures fit inside the hold.');

        $reservation->refresh();
        $wallet->refresh();

        $this->assertSame(20000, $reservation->captured_amount);
        $this->assertSame(0, $wallet->reserved_balance_cached);
        // 500 deposited, 200 held, 200 spent.
        $this->assertSame(30000, $wallet->available_balance_cached);
        $this->assertTrue($wallet->isReconciled());
    }

    #[Test]
    public function an_entry_cannot_be_reversed_twice_concurrently(): void
    {
        $wallet = $this->walletWith('500.00');

        $entry = app(WalletService::class)->debit(
            $wallet,
            Money::of('100.00', 'BDT'),
            LedgerEntryType::ServiceFee,
            'Fee to be reversed',
        );

        $entryId = $entry->getKey();

        $result = $this->runConcurrently(4, function () use ($entryId): bool {
            $entry = \App\Domains\Wallet\Models\LedgerEntry::query()
                ->withoutGlobalScopes()
                ->findOrFail($entryId);

            try {
                app(WalletService::class)->reverse($entry, 'Charged in error');

                return true;
            } catch (\Throwable) {
                // Either the application check or the unique index refuses it;
                // both are correct, and only one worker may win.
                return false;
            }
        });

        $this->assertSame(1, $result['succeeded'], 'An entry was reversed more than once.');

        $wallet->refresh();
        $this->assertSame(50000, $wallet->available_balance_cached);
        $this->assertTrue($wallet->isReconciled());
    }

    private function walletWith(string $amount, bool $deposit = true): Wallet
    {
        $organization = Organization::factory()->create(['default_currency' => 'BDT']);
        $wallet = app(WalletService::class)->walletFor($organization);

        if ($deposit && $amount !== '0.00') {
            app(WalletService::class)->deposit(
                $wallet,
                Money::of($amount, 'BDT'),
                'Opening balance',
            );
        }

        return $wallet->refresh();
    }
}
