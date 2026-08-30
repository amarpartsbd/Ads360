<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exchange rates (spec §35).
 *
 * Two rates per pair: the market rate the platform observed, and the client
 * rate it actually transacts at. The difference is the currency markup, and
 * keeping both means a client's rate can be explained rather than asserted.
 *
 * Rates are effective-dated and never edited. A change is a new row, so a
 * historical transaction can always be re-read against the rate that applied
 * when it happened — spec §35 is explicit that history is never recalculated
 * with today's numbers.
 *
 * Rates are stored as decimal strings, not floats: a rate multiplies money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            // Null for the platform's own rates. A tenant may be given its own
            // rate card without affecting anyone else.
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('base_currency', 3);
            $table->string('quote_currency', 3);

            // 18 digits with 8 decimal places: enough for any currency pair
            // without ever reaching for a float.
            $table->decimal('market_rate', 18, 8);
            $table->decimal('client_rate', 18, 8);

            $table->timestamp('effective_from');

            // Null means "still in force". Closed off when a newer rate starts.
            $table->timestamp('effective_until')->nullable();

            $table->string('source', 64)->default('MANUAL');
            $table->string('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['base_currency', 'quote_currency', 'effective_from']);
            $table->index(['tenant_id', 'base_currency', 'quote_currency']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE exchange_rates
            ADD CONSTRAINT exchange_rates_positive
            CHECK (market_rate > 0 AND client_rate > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE exchange_rates
            ADD CONSTRAINT exchange_rates_distinct_currencies
            CHECK (base_currency <> quote_currency)
        SQL);

        /*
         * A window may be zero-length but never negative.
         *
         * Zero-length is a real case: publishing a rate and immediately
         * correcting a typo supersedes the first within the same second, and
         * that rate simply never applied to anything. Resolution asks for
         * `effective_until > at`, so a zero-length window is never selected.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE exchange_rates
            ADD CONSTRAINT exchange_rates_valid_window
            CHECK (effective_until IS NULL OR effective_until >= effective_from)
        SQL);

        /*
         * At most one open-ended rate per pair per scope. Two rows both claiming
         * to be "current" would make the rate a matter of query order.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX exchange_rates_one_current_per_tenant_pair
            ON exchange_rates (tenant_id, base_currency, quote_currency)
            WHERE effective_until IS NULL AND tenant_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX exchange_rates_one_current_platform_pair
            ON exchange_rates (base_currency, quote_currency)
            WHERE effective_until IS NULL AND tenant_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
