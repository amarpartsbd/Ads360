<?php

declare(strict_types=1);

use App\Domains\Assistant\Enums\RecommendationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recommendations the platform has made (spec §45, §46, §47).
 *
 * ## Why provenance is a column rather than a note
 *
 * §46 asks that AI-generated content be stored with its source metadata, and
 * the reason is not bookkeeping. A client looking at a headline needs to know
 * whether a person wrote it or a model did; a reviewer deciding whether to
 * approve a campaign needs to know whether the targeting was reasoned about or
 * generated; and if a model turns out to have been producing bad advice for a
 * month, someone has to be able to find every piece of it. None of that is
 * possible if the source lives in a free-text field.
 *
 * `source_driver`, `source_model` and `source_version` are therefore required
 * on every row, including deterministic ones — where they record that no model
 * was involved at all, which is itself the useful fact.
 *
 * ## Why acceptance is recorded here and execution is not
 *
 * §45 is explicit: AI output is a recommendation, and a person approves before
 * financial execution. Accepting one of these produces a draft that then goes
 * through the same review and approval as anything a person typed. This table
 * records that someone accepted; it never records that something ran, because
 * nothing here runs anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Present when the recommendation is about one campaign (§47).
            $table->foreignId('campaign_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('kind', 32)->index();
            $table->string('status', 32)->index();

            $table->string('headline');
            $table->text('body');

            // Whatever the recommendation proposes, in the shape its kind
            // expects. Never executed from here — see the class docblock.
            $table->jsonb('payload')->default('{}');

            /*
             * Provenance. Required, so a row can always answer "where did this
             * come from" (spec §46).
             */
            $table->string('source_driver', 32);
            $table->string('source_model', 64);
            $table->string('source_version', 32);

            /*
             * A digest of what was asked, not the text of it. A brief can name
             * a client's unannounced product or their margins, and keeping it
             * would put that in a table read by every screen that lists
             * recommendations (§53, §54).
             */
            $table->string('prompt_digest', 64)->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'kind', 'created_at']);
        });

        $statuses = implode(',', array_map(
            static fn (string $value): string => "'{$value}'",
            RecommendationStatus::values(),
        ));

        DB::statement(
            "ALTER TABLE recommendations
             ADD CONSTRAINT recommendations_status_is_known
             CHECK (status IN ({$statuses}))"
        );

        /*
         * A decision has to say who made it. §45's requirement that a person
         * approves is worth nothing if the record cannot name them.
         */
        DB::statement(
            "ALTER TABLE recommendations
             ADD CONSTRAINT recommendations_decisions_are_attributed
             CHECK (
                 status = 'OFFERED'
                 OR status = 'EXPIRED'
                 OR (decided_by IS NOT NULL AND decided_at IS NOT NULL)
             )"
        );

        DB::statement(
            "ALTER TABLE recommendations
             ADD CONSTRAINT recommendations_payload_is_an_object
             CHECK (jsonb_typeof(payload) = 'object')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
