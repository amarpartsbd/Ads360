<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Budget holds against a wallet (spec §32).
 *
 * When a campaign is approved its budget moves from available to reserved. As
 * spend is reported the reservation is drawn down; whatever is left when the
 * campaign ends is released. The client's money never leaves the wallet until
 * it is actually spent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_reservations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();

            // What the hold is for. A campaign in practice, but kept
            // polymorphic so the campaign module can land without a migration
            // here.
            $table->string('reference_type');
            $table->string('reference_id');

            $table->bigInteger('amount');
            $table->bigInteger('captured_amount')->default(0);
            $table->bigInteger('released_amount')->default(0);
            $table->string('currency', 3);

            $table->string('status', 32)->index();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['wallet_id', 'status']);
            $table->index(['reference_type', 'reference_id']);
        });

        /*
         * A reservation can never give back more than it held. Captured plus
         * released is what has left the hold, and it may not exceed the amount
         * put into it — otherwise a double release would mint money.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE wallet_reservations
            ADD CONSTRAINT wallet_reservations_within_amount
            CHECK (
                amount > 0
                AND captured_amount >= 0
                AND released_amount >= 0
                AND captured_amount + released_amount <= amount
            )
        SQL);

        // One open hold per thing being held for. Two live reservations against
        // the same campaign would double-reserve the client's balance.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX wallet_reservations_one_open_per_reference
            ON wallet_reservations (reference_type, reference_id)
            WHERE status IN ('HELD', 'PARTIALLY_CAPTURED')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_reservations');
    }
};
