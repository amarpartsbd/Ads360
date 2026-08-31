<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two additions to maker-checker (spec §12, §25).
 *
 * **`requires_senior_approval`** — §25 asks for "Finance + Senior Approval" on
 * the largest movements, which is not the same as two of the same person. A
 * request that needs a senior signature records that it does, so the queue can
 * say what is still missing rather than sitting at "1 of 2" with no
 * explanation of why the second one has not landed.
 *
 * **`elevation_reason`** — why this request needs more scrutiny than its size
 * alone would ask for. Today that is a high-risk client (spec §12). It is
 * stored rather than recomputed because the answer has to be the one that was
 * true when the request was raised: an account whose risk has since fallen
 * should not make a pending request look like it was escalated for no reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table): void {
            $table->boolean('requires_senior_approval')->default(false);
            $table->timestamp('senior_approved_at')->nullable();
            $table->foreignId('senior_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('elevation_reason')->nullable();
        });

        /*
         * A senior signature that names nobody is not a signature. Enforced
         * here as well as in the service, because this row is the record that
         * a second kind of person looked.
         */
        DB::statement(
            'ALTER TABLE approval_requests
             ADD CONSTRAINT approval_requests_senior_signature_is_attributed
             CHECK (
                 senior_approved_at IS NULL
                 OR senior_approved_by IS NOT NULL
             )'
        );

        // A senior signature only means something on a request that asked for
        // one; recording one elsewhere would make the column unreadable.
        DB::statement(
            'ALTER TABLE approval_requests
             ADD CONSTRAINT approval_requests_senior_signature_was_required
             CHECK (
                 senior_approved_at IS NULL
                 OR requires_senior_approval = true
             )'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE approval_requests DROP CONSTRAINT IF EXISTS approval_requests_senior_signature_was_required');
        DB::statement('ALTER TABLE approval_requests DROP CONSTRAINT IF EXISTS approval_requests_senior_signature_is_attributed');

        Schema::table('approval_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('senior_approved_by');
            $table->dropColumn(['requires_senior_approval', 'senior_approved_at', 'elevation_reason']);
        });
    }
};
