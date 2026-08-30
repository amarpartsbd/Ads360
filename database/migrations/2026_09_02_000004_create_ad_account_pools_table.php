<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pools group managed ad accounts so allocation can draw from a named set
 * rather than the whole inventory (spec §18).
 *
 * The rules live here; the engine that consumes them arrives with the campaign
 * work in the next phase. Storing them now means an administrator can describe
 * a pool's intent ("verified clients only, risk under 40") before anything
 * automatic depends on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_account_pools', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->text('description')->nullable();

            // A pool is provider- and currency-homogeneous: allocation cannot
            // reasonably swap a Meta account for a Google one, nor a BDT
            // account for a USD one, without changing what was agreed.
            $table->string('provider', 32)->index();
            $table->string('currency', 3);

            $table->string('status', 32)->index();

            /*
             * Allocation rules, written by AllocationRules and read back the
             * same way. Kept as a document because the shape is expected to
             * grow, and because no query needs to filter on its interior.
             */
            $table->jsonb('allocation_rules')->default('{}');

            $table->string('selection_strategy', 32);

            // Ordering between pools when more than one accepts a client.
            $table->unsignedTinyInteger('priority')->default(50);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['provider', 'status', 'priority']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ad_account_pools
            ADD CONSTRAINT ad_account_pools_priority_bounds
            CHECK (priority <= 100)
        SQL);

        Schema::create('ad_account_pool_members', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('ad_account_pool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_account_id')->constrained()->cascadeOnDelete();

            // Relative share when the pool distributes by weight. Ignored by
            // the other strategies, which is why it carries a usable default.
            $table->unsignedSmallInteger('weight')->default(1);

            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ad_account_pool_id', 'ad_account_id'], 'ad_account_pool_members_unique');
            $table->index('ad_account_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ad_account_pool_members
            ADD CONSTRAINT ad_account_pool_members_weight_positive
            CHECK (weight >= 1)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_account_pool_members');
        Schema::dropIfExists('ad_account_pools');
    }
};
