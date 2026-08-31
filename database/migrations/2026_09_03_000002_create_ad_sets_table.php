<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ad sets — the targeting and bidding layer between a campaign and its ads
 * (spec §21).
 *
 * Providers name this differently (Meta calls it an ad set, Google an ad
 * group). One vocabulary is used throughout and the adapter translates, so no
 * caller has to know which platform it is building for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_sets', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('status', 32)->index();

            /*
             * Targeting as a document. Its interior is validated by the
             * Targeting value object on the way in and out, and no query
             * filters on it — the shape differs per provider and is expected
             * to grow.
             */
            $table->jsonb('targeting')->default('{}');

            $table->string('optimization_goal', 48)->nullable();
            $table->string('bid_strategy', 48);
            $table->bigInteger('bid_amount')->nullable();

            // Null means the ad set draws on the campaign budget rather than
            // holding one of its own.
            $table->bigInteger('budget_amount')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->string('provider_ad_set_id', 128)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('last_error')->nullable();

            $table->jsonb('metadata')->default('{}');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['campaign_id', 'status']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ad_sets_unique_provider_id
            ON ad_sets (campaign_id, provider_ad_set_id)
            WHERE provider_ad_set_id IS NOT NULL AND deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ad_sets
            ADD CONSTRAINT ad_sets_sane_amounts
            CHECK (
                (bid_amount IS NULL OR bid_amount > 0)
                AND (budget_amount IS NULL OR budget_amount > 0)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ad_sets
            ADD CONSTRAINT ad_sets_schedule_ordered
            CHECK (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_sets');
    }
};
