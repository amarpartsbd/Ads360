<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generated report files (spec §39).
 *
 * An export is a snapshot of a client's performance data sitting on disk. Two
 * things follow from that, and both are columns here.
 *
 * It has an owner, so it can be authorised: `organization_id` decides who may
 * download it, and it is never inferred from the file name.
 *
 * And it expires. A CSV of a client's spend and conversions should not live on
 * a disk indefinitely because somebody once clicked a button; `expires_at` is
 * what the cleanup sweep reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('type', 48)->index();
            $table->string('status', 32)->index();

            // What was asked for, so a file can be explained later and a
            // repeat request recognised.
            $table->jsonb('filters')->default('{}');

            $table->date('period_start');
            $table->date('period_end');

            // Absent until the job finishes. A path with no file is worse than
            // no path at all.
            $table->string('storage_path')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->unsignedInteger('row_count')->nullable();

            $table->string('last_error')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        // A finished export must have something to download.
        DB::statement(<<<'SQL'
            ALTER TABLE report_exports
            ADD CONSTRAINT report_exports_completed_rows_have_a_file
            CHECK (status <> 'READY' OR storage_path IS NOT NULL)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE report_exports
            ADD CONSTRAINT report_exports_period_ordered
            CHECK (period_end >= period_start)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
