<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client wallets (spec §31).
 *
 * The two balance columns are named `_cached` on purpose: they are a
 * denormalised read of the ledger, not the source of truth. Anything that
 * changes them does so inside the same transaction that writes the ledger
 * entries they summarise, and reconciliation recomputes them from the ledger.
 *
 * Amounts are integer minor units — never floating point (spec §59, §60).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('currency', 3);

            $table->bigInteger('available_balance_cached')->default(0);
            $table->bigInteger('reserved_balance_cached')->default(0);

            $table->string('status', 32)->index();

            // Set when a wallet is frozen, so support can see why without
            // reading the audit trail.
            $table->string('status_reason')->nullable();

            $table->timestamp('last_reconciled_at')->nullable();

            $table->timestamps();

            // One wallet per organization per currency. Multi-currency clients
            // get a wallet each rather than a single mixed balance, so no
            // amount is ever added to one in a different currency.
            $table->unique(['organization_id', 'currency']);
            $table->index(['tenant_id', 'status']);
        });

        /*
         * A wallet may never go negative. This is the invariant the whole
         * module exists to protect, so it is enforced by the database rather
         * than only by the service that maintains it: if a bug, a migration or
         * a console command ever tries to overdraw a wallet, the write fails
         * instead of silently creating money.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE wallets
            ADD CONSTRAINT wallets_balances_non_negative
            CHECK (available_balance_cached >= 0 AND reserved_balance_cached >= 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
