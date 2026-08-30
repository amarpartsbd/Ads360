<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's managed advertising account inventory (spec §17).
 *
 * These are the platform's own accounts, not a client's. They are not
 * tenant-scoped: allocation draws from a shared pool, and an account may serve
 * different clients over its life. Access is decided by policy, and a client
 * never sees this table at all.
 *
 * Money is integer minor units throughout, like everywhere else (spec §59).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_accounts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->string('provider', 32)->index();
            $table->string('external_account_id', 128);
            $table->string('name');

            $table->string('currency', 3);
            $table->string('timezone', 64);

            $table->string('status', 32)->index();
            $table->string('health_status', 32)->index();
            $table->string('billing_status', 32)->index();

            // Null means no limit is configured. Distinct from zero, which
            // would mean the account may not spend at all.
            $table->bigInteger('daily_spend_limit')->nullable();
            $table->bigInteger('monthly_spend_limit')->nullable();

            $table->bigInteger('current_daily_spend')->default(0);
            $table->bigInteger('current_monthly_spend')->default(0);

            /*
             * How much of the account's headroom is already committed to
             * approved campaigns. Kept alongside actual spend because
             * allocation must consider what is promised, not only what has
             * been used (spec §19).
             */
            $table->bigInteger('committed_amount')->default(0);

            // 0–100, mirroring the client risk scale of spec §12 so the two can
            // be compared during allocation.
            $table->unsignedTinyInteger('risk_score')->default(0);

            // Administrator's thumb on the scale during allocation (spec §19).
            $table->unsignedTinyInteger('allocation_priority')->default(50);

            // The connection used to reach this account, when it is operated
            // through an authorised grant rather than platform credentials.
            $table->foreignId('provider_connection_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->timestamp('last_synced_at')->nullable();

            /*
             * Consecutive failed health checks. One failed request says
             * nothing about an account; a run of them does (spec §29).
             */
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason')->nullable();

            $table->jsonb('metadata')->default('{}');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['provider', 'status', 'health_status']);
            $table->index(['status', 'allocation_priority']);
        });

        // One row per provider account. Two rows for the same external account
        // would let allocation hand it out twice over.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ad_accounts_unique_external
            ON ad_accounts (provider, external_account_id)
            WHERE deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ad_accounts
            ADD CONSTRAINT ad_accounts_sane_amounts
            CHECK (
                current_daily_spend >= 0
                AND current_monthly_spend >= 0
                AND committed_amount >= 0
                AND (daily_spend_limit IS NULL OR daily_spend_limit >= 0)
                AND (monthly_spend_limit IS NULL OR monthly_spend_limit >= 0)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ad_accounts
            ADD CONSTRAINT ad_accounts_score_bounds
            CHECK (risk_score <= 100 AND allocation_priority <= 100)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_accounts');
    }
};
