<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One deposit entry per payment, enforced in the ledger (spec §30).
 *
 * The payments table already refuses two payments pointing at one entry. This
 * is the other direction: two entries crediting one payment. Together they make
 * a double credit impossible from either side, whatever the application does —
 * a retried webhook, a duplicated queue job, or two finance staff clicking
 * verify at the same moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ledger_entries_one_deposit_per_reference
            ON ledger_entries (reference_type, reference_id)
            WHERE type = 'DEPOSIT' AND reference_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ledger_entries_one_deposit_per_reference');
    }
};
