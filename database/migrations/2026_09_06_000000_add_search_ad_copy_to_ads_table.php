<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Further headlines and descriptions for one ad (spec §21, §23).
 *
 * Providers disagree about what an ad is. Meta shows the copy that was
 * written. Google's responsive search ads rotate several headlines and
 * descriptions and refuse an ad carrying fewer than three and two.
 *
 * The alternative to collecting them would be for the Google adapter to invent
 * the missing ones — cutting a headline down to thirty characters, or
 * repeating one to make up the count. Both put words in a client's mouth that
 * they never approved and cannot see before the ad runs, so the platform asks
 * for them instead.
 *
 * Nullable and defaulted to an empty list: an ad written for Meta needs none
 * of this, and existing rows are not made invalid by the column arriving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ads', function (Blueprint $table): void {
            $table->jsonb('extra_headlines')->default('[]');
            $table->jsonb('extra_descriptions')->default('[]');
        });

        /*
         * Enforced in the database as well as in the request, because these
         * are sent verbatim to a provider. A malformed document here is an ad
         * that fails at publish time, long after whoever wrote it has gone.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE ads
            ADD CONSTRAINT ads_extra_copy_is_a_list
            CHECK (
                jsonb_typeof(extra_headlines) = 'array'
                AND jsonb_typeof(extra_descriptions) = 'array'
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('ads', function (Blueprint $table): void {
            $table->dropColumn(['extra_headlines', 'extra_descriptions']);
        });
    }
};
