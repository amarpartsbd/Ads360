<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns (spec §21).
 *
 * Money is integer minor units and every figure here is computed server-side.
 * The browser sends a budget the client typed; the fees, tax and total on this
 * row are derived from it by the pricing engine and frozen at submission, so
 * what the client agreed to is what they are charged (Rule 8, spec §22).
 *
 * The ad account is absent until allocation runs at approval, and the provider
 * identifiers are absent until publishing succeeds. Both being null is how the
 * system knows work is still outstanding — nothing infers state from a
 * timestamp alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('provider', 32)->index();
            $table->string('objective', 48);
            $table->string('status', 32)->index();

            $table->string('currency', 3);

            $table->string('budget_type', 32);

            /*
             * What the client asked to spend on advertising. Fees are charged
             * on top of this, not taken out of it, so the client's stated
             * budget is what reaches the provider (spec §22).
             */
            $table->bigInteger('budget_amount');

            // Derived at submission by the pricing engine and never editable
            // afterwards. The snapshot records the plan as it stood.
            $table->bigInteger('fee_total')->default(0);
            $table->bigInteger('charged_total')->default(0);
            $table->jsonb('pricing_snapshot')->default('{}');

            // Spend actually drawn from the reservation so far.
            $table->bigInteger('captured_amount')->default(0);
            // What the provider says has been spent, which may run ahead of
            // what we have captured between syncs.
            $table->bigInteger('reported_spend')->default(0);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Assigned by allocation at approval (spec §19).
            $table->foreignId('ad_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ad_account_pool_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('wallet_reservation_id')->nullable()->constrained()->nullOnDelete();

            // Written only once the provider has confirmed the campaign exists.
            $table->string('provider_campaign_id', 128)->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            // Shown to the client, so it is written in plain language.
            $table->text('review_notes')->nullable();
            $table->string('last_error')->nullable();

            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->jsonb('metadata')->default('{}');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'organization_id', 'status']);
            $table->index(['status', 'submitted_at']);
            $table->index(['ad_account_id', 'status']);
        });

        /*
         * One campaign per provider identifier. This is the last line of the
         * idempotency defence: even if a retry somehow reached the provider
         * twice, two rows could not both claim the same published campaign.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX campaigns_unique_provider_id
            ON campaigns (provider, provider_campaign_id)
            WHERE provider_campaign_id IS NOT NULL AND deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE campaigns
            ADD CONSTRAINT campaigns_sane_amounts
            CHECK (
                budget_amount > 0
                AND fee_total >= 0
                AND charged_total >= 0
                AND captured_amount >= 0
                AND reported_spend >= 0
                AND captured_amount <= charged_total
            )
        SQL);

        // A campaign that ends before it starts would be published as a
        // schedule no provider would accept.
        DB::statement(<<<'SQL'
            ALTER TABLE campaigns
            ADD CONSTRAINT campaigns_schedule_ordered
            CHECK (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at)
        SQL);

        /*
         * Past review, a campaign must carry what review gave it. Enforced
         * here rather than only in the approval action, so no other code path
         * can leave an approved campaign without money held or an account to
         * run on.
         *
         * FAILED is in the list because a campaign that could not be published
         * still holds its reservation — that is what lets it be retried
         * without going back through review.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE campaigns
            ADD CONSTRAINT campaigns_approved_rows_are_resourced
            CHECK (
                status NOT IN ('APPROVED', 'PUBLISHING', 'ACTIVE', 'PAUSED', 'COMPLETED', 'FAILED')
                OR (ad_account_id IS NOT NULL AND wallet_reservation_id IS NOT NULL AND charged_total > 0)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
