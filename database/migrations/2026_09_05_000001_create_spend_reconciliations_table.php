<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where provider spend is checked against the ledger (spec §78).
 *
 * The platform holds two accounts of the same money: what a provider says a
 * campaign spent, and what the wallet actually captured. They should agree.
 * When they do not, something is wrong that nobody would otherwise notice —
 * a sync that stopped running, a provider restating a month-old day, a
 * capture that failed silently.
 *
 * This table is the record of every comparison, not only the ones that
 * disagreed. A run that found nothing is itself evidence: it says the two
 * accounts agreed on that date, which is what someone auditing the platform
 * needs to see.
 *
 * **Nothing here moves money.** A discrepancy is raised for a human to settle
 * through the maker-checker path (§25). Reconciliation that adjusted balances
 * on its own would be a scheduled job with write access to client funds and
 * no second pair of eyes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spend_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();

            $table->date('period_start');
            $table->date('period_end');

            $table->string('currency', 3);

            // The two accounts being compared, both in minor units.
            $table->bigInteger('provider_spend');
            $table->bigInteger('ledger_spend');

            // provider minus ledger. Positive means the provider says more was
            // spent than the platform has charged for — the direction that
            // costs the platform money.
            $table->bigInteger('variance');

            $table->string('status', 32)->index();

            // Set when someone settles it, so the trail leads somewhere.
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->jsonb('metadata')->default('{}');

            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['status', 'checked_at']);
        });

        /*
         * One comparison per campaign per period. Re-running a check updates
         * the existing row rather than filling the queue with copies of the
         * same discrepancy every hour.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX spend_reconciliations_one_per_period
            ON spend_reconciliations (campaign_id, period_start, period_end)
        SQL);

        // The variance is derived, and a row where it does not follow from the
        // two figures beside it would make the whole table untrustworthy.
        DB::statement(<<<'SQL'
            ALTER TABLE spend_reconciliations
            ADD CONSTRAINT spend_reconciliations_variance_is_consistent
            CHECK (variance = provider_spend - ledger_spend)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE spend_reconciliations
            ADD CONSTRAINT spend_reconciliations_period_ordered
            CHECK (period_end >= period_start)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE spend_reconciliations
            ADD CONSTRAINT spend_reconciliations_resolved_rows_are_explained
            CHECK (
                status <> 'RESOLVED'
                OR (resolved_at IS NOT NULL AND resolution_note IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('spend_reconciliations');
    }
};
