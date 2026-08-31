<?php

declare(strict_types=1);

use App\Domains\Client\Enums\RiskLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An organization's risk profile (spec §12).
 *
 * One row per organization, rewritten by each assessment. The score alone is
 * useless to whoever has to act on it, so the factors that produced it are
 * stored beside it — §12 requires risk decisions to be explainable, and a
 * number with no reasons cannot be argued with, appealed, or corrected.
 *
 * `manual_flag_*` is the one input a person supplies. It is separate from the
 * computed factors because it has to survive reassessment: a compliance officer
 * who flags an account should not find their flag gone an hour later because a
 * scheduled job recomputed everything around it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_risk_profiles', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // One profile per organization, enforced rather than assumed: two
            // would mean two different answers to "how risky is this client".
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('score')->default(0);
            $table->string('level', 16)->index();

            /*
             * The reasons, as a list of {factor, points, detail}. Stored rather
             * than recomputed on read so the queue shows what the score meant
             * *when it was assessed*, not what the same inputs would produce
             * today.
             */
            $table->jsonb('factors')->default('[]');

            $table->timestamp('assessed_at')->nullable();

            // What a person did about it, if anything.
            $table->boolean('manual_flag')->default(false);
            $table->string('manual_flag_reason')->nullable();
            $table->foreignId('manual_flag_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('manual_flagged_at')->nullable();

            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_note')->nullable();

            $table->timestamps();

            // The compliance queue reads by level and staleness.
            $table->index(['level', 'assessed_at']);
        });

        DB::statement(
            'ALTER TABLE organization_risk_profiles
             ADD CONSTRAINT organization_risk_profiles_score_is_a_percentage
             CHECK (score >= 0 AND score <= 100)'
        );

        $levels = implode(',', array_map(
            static fn (string $value): string => "'{$value}'",
            RiskLevel::values(),
        ));

        DB::statement(
            "ALTER TABLE organization_risk_profiles
             ADD CONSTRAINT organization_risk_profiles_level_is_known
             CHECK (level IN ({$levels}))"
        );

        /*
         * The score and the band have to agree. They are stored separately
         * because the queue filters on one and sorts on the other, and a row
         * where they disagreed would make the queue lie in whichever direction
         * the reader happened to trust.
         */
        DB::statement(
            "ALTER TABLE organization_risk_profiles
             ADD CONSTRAINT organization_risk_profiles_level_matches_score
             CHECK (
                 (score <= 30 AND level = 'LOW')
                 OR (score > 30 AND score <= 60 AND level = 'MEDIUM')
                 OR (score > 60 AND score <= 80 AND level = 'HIGH')
                 OR (score > 80 AND level = 'CRITICAL')
             )"
        );

        // A flag is a person's decision and has to say who made it and why.
        DB::statement(
            'ALTER TABLE organization_risk_profiles
             ADD CONSTRAINT organization_risk_profiles_flags_are_attributed
             CHECK (
                 manual_flag = false
                 OR (manual_flag_reason IS NOT NULL AND manual_flagged_at IS NOT NULL)
             )'
        );

        DB::statement(
            "ALTER TABLE organization_risk_profiles
             ADD CONSTRAINT organization_risk_profiles_factors_are_a_list
             CHECK (jsonb_typeof(factors) = 'array')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_risk_profiles');
    }
};
