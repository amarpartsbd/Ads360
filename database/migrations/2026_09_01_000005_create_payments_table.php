<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments into a wallet (spec §33, §34).
 *
 * One table covers both gateway payments and manual deposits: the difference is
 * the method and who confirms it, not the shape of the record. Both end the
 * same way — a verified payment credits the ledger exactly once.
 *
 * A payment is never the source of a balance. It is the *reason* for a ledger
 * entry, and the ledger remains the truth (spec §31).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();

            // Human-readable and quotable in a support conversation.
            $table->string('reference', 32)->unique();

            $table->string('method', 32)->index();
            $table->string('provider', 32)->nullable();

            $table->bigInteger('amount');
            $table->string('currency', 3);

            $table->string('status', 32)->index();

            /*
             * Idempotency (spec §30). A retried submission carrying the same
             * key finds the original payment instead of creating a second one,
             * and the unique index makes that true even when two retries arrive
             * at the same instant.
             */
            $table->string('idempotency_key', 64)->nullable();

            // The client's own reference: a bKash transaction id, a bank slip
            // number. Never trusted as proof on its own — finance verifies it.
            $table->string('external_reference', 128)->nullable();

            // Set by the gateway once it has its own identifier for the charge.
            $table->string('provider_reference', 128)->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('rejection_reason')->nullable();

            // Proof of payment, on the private documents disk (spec §55).
            $table->string('proof_disk', 32)->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_filename')->nullable();

            // The ledger entry this payment produced. Set once, when verified.
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');

            $table->jsonb('metadata')->default('{}');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['status', 'submitted_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payments
            ADD CONSTRAINT payments_amount_positive
            CHECK (amount > 0)
        SQL);

        /*
         * A verified payment must point at the ledger entry that credited it,
         * and an unverified one must not. Without this a payment could be
         * marked paid while no money ever reached the wallet — or the reverse.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE payments
            ADD CONSTRAINT payments_verified_has_ledger_entry
            CHECK (
                (status = 'VERIFIED' AND ledger_entry_id IS NOT NULL AND verified_at IS NOT NULL)
                OR (status <> 'VERIFIED' AND ledger_entry_id IS NULL)
            )
        SQL);

        // One payment per ledger entry, and one idempotency key per
        // organization: the two guarantees that make a double credit impossible
        // rather than merely unlikely.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payments_one_per_ledger_entry
            ON payments (ledger_entry_id)
            WHERE ledger_entry_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payments_idempotency_key_unique
            ON payments (organization_id, idempotency_key)
            WHERE idempotency_key IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
