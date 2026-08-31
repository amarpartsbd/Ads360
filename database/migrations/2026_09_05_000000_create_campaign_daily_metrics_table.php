<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily performance figures, as the provider reports them (spec §38, §78).
 *
 * One row per campaign per day, and the unique index is the whole design.
 * Providers *restate*: an attribution window that closes three days after a
 * click moves spend and conversions onto days already reported. A table that
 * appended each sync would show a client the same day several times over and
 * a total that grew every hour.
 *
 * So ingestion upserts on (campaign, date), and a day's row always holds the
 * provider's latest word on that day rather than the first.
 *
 * Money is integer minor units in the campaign's currency, like everywhere
 * else (spec §59). Counts are plain integers. Nothing derived — click-through
 * rate, cost per click — is stored: those are ratios of two columns that are
 * already here, and storing them would be a third number that could disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_daily_metrics', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();

            // Nullable so a campaign-level row can sit alongside the finer
            // ones; a null means "the whole campaign for that day".
            $table->foreignId('ad_set_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ad_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('provider', 32)->index();

            /*
             * The date in the *ad account's* timezone, not ours. A provider
             * reports days in the account's own timezone, and re-interpreting
             * them in UTC would shift spend across day boundaries and make
             * reconciliation disagree with the provider's own interface.
             */
            $table->date('metric_date');

            $table->string('currency', 3);

            $table->bigInteger('spend')->default(0);
            $table->bigInteger('impressions')->default(0);
            $table->bigInteger('clicks')->default(0);
            $table->bigInteger('reach')->default(0);
            $table->bigInteger('conversions')->default(0);

            // What the conversions were worth, when the provider reports it.
            $table->bigInteger('conversion_value')->default(0);

            /*
             * When the provider last told us about this day. A day whose
             * figures were last fetched a week ago has almost certainly been
             * restated since.
             */
            $table->timestamp('reported_at');

            $table->jsonb('metadata')->default('{}');

            $table->timestamps();

            $table->index(['organization_id', 'metric_date']);
            $table->index(['campaign_id', 'metric_date']);
            $table->index(['tenant_id', 'metric_date']);
        });

        /*
         * One row per entity per day. `COALESCE` because PostgreSQL treats
         * NULLs as distinct in a unique index, so without it a campaign-level
         * row could be inserted many times over for the same day.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX campaign_daily_metrics_one_row_per_day
            ON campaign_daily_metrics (
                campaign_id,
                COALESCE(ad_set_id, 0),
                COALESCE(ad_id, 0),
                metric_date
            )
        SQL);

        // Providers do not report negative performance. A negative here means
        // a parsing mistake, and it should fail loudly rather than quietly
        // reduce a client's reported spend.
        DB::statement(<<<'SQL'
            ALTER TABLE campaign_daily_metrics
            ADD CONSTRAINT campaign_daily_metrics_non_negative
            CHECK (
                spend >= 0
                AND impressions >= 0
                AND clicks >= 0
                AND reach >= 0
                AND conversions >= 0
                AND conversion_value >= 0
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_daily_metrics');
    }
};
