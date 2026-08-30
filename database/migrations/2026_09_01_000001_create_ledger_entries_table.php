<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The financial source of truth (spec §31).
 *
 * Append-only. Entries are never updated and never deleted; a mistake is
 * corrected by writing a reversal that points back at the entry it undoes
 * (spec §62). Every balance the application shows can be recomputed by
 * replaying this table.
 *
 * Entries that belong to one business event — a spend and the fees charged on
 * it, say — share a `transaction_group_id`, so the event can be read back as a
 * unit and reversed as a unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();

            // Groups the entries written by one business event.
            $table->ulid('transaction_group_id')->index();

            $table->string('type', 32)->index();

            // What caused the entry — a payment, a campaign, an invoice.
            // Polymorphic by string rather than a foreign key, because the
            // ledger outlives whatever it references and must never cascade.
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();

            /*
             * Movement of the *available* balance. Exactly one is non-zero: two
             * columns rather than one signed amount because that is how a
             * ledger is read and audited, and because it makes an accidental
             * sign flip impossible to write.
             *
             *     available balance = SUM(credit) - SUM(debit)
             */
            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);

            /*
             * Movement of the *reserved* balance, signed.
             *
             *     reserved balance = SUM(reserved_delta)
             *
             * Only RESERVE and RELEASE entries set it. Reserving debits
             * available and adds here; releasing credits available and
             * subtracts. Spending against a hold is therefore recorded as two
             * entries in one group — a RELEASE back to available, then a
             * CAMPAIGN_SPEND debit out of the wallet — which keeps every entry
             * single-sided and shows exactly what happened.
             */
            $table->bigInteger('reserved_delta')->default(0);

            $table->string('currency', 3);

            // Balances immediately after this entry, so any row can be checked
            // against the running total without replaying the whole table.
            $table->bigInteger('balance_snapshot');
            $table->bigInteger('reserved_snapshot');

            $table->string('description');

            // Which entry this one reverses, if any.
            $table->foreignId('reverses_entry_id')->nullable()->constrained('ledger_entries');

            // The exchange rate and pricing in force when this was written, so
            // history is never recalculated with today's numbers (spec §35).
            $table->jsonb('rate_snapshot')->nullable();
            $table->jsonb('pricing_snapshot')->nullable();
            $table->jsonb('metadata')->default('{}');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'created_at']);
            $table->index(['organization_id', 'type', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        /*
         * An entry moves money in exactly one direction, and never a negative
         * amount. Without this a single malformed write could corrupt every
         * balance derived from the table.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries
            ADD CONSTRAINT ledger_entries_single_sided
            CHECK (
                debit >= 0
                AND credit >= 0
                AND (debit = 0) <> (credit = 0)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries
            ADD CONSTRAINT ledger_entries_snapshots_non_negative
            CHECK (balance_snapshot >= 0 AND reserved_snapshot >= 0)
        SQL);

        // Only a reservation movement touches the reserved balance, and it
        // always moves it opposite to the available side.
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries
            ADD CONSTRAINT ledger_entries_reserved_delta_matches_type
            CHECK (
                (type = 'RESERVE' AND reserved_delta = debit AND credit = 0)
                OR (type = 'RELEASE' AND reserved_delta = -credit AND debit = 0)
                OR (type NOT IN ('RESERVE', 'RELEASE') AND reserved_delta = 0)
            )
        SQL);

        // An entry may be reversed at most once. A second reversal of the same
        // entry would credit the client twice for one mistake.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ledger_entries_single_reversal
            ON ledger_entries (reverses_entry_id)
            WHERE reverses_entry_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
