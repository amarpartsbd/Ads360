<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded creative assets (spec §23).
 *
 * A lean library rather than the full creative module: an ad cannot be
 * published without an image or video, so the storage has to exist before the
 * campaign engine does. Approval workflows and versioning arrive with the
 * creative phase proper.
 *
 * Files live on the private disk, identified by magic bytes rather than the
 * declared MIME type, exactly as verification documents are (spec §13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creatives', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('type', 32)->index();

            // Path on the private disk. Never a public URL.
            $table->string('storage_path');
            $table->string('media_type', 128);
            $table->unsignedBigInteger('byte_size');

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            /*
             * SHA-256 of the file. Lets the same asset be recognised across
             * uploads, and gives publishing something stable to compare when a
             * provider asks whether it has seen this creative before.
             */
            $table->string('checksum', 64)->index();

            $table->string('status', 32)->index();

            $table->jsonb('metadata')->default('{}');

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'type', 'status']);
        });

        // One stored copy per organization per file. Re-uploading the same
        // asset returns the existing row instead of filling the disk.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX creatives_unique_checksum_per_organization
            ON creatives (organization_id, checksum)
            WHERE deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE creatives
            ADD CONSTRAINT creatives_positive_size
            CHECK (byte_size > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('creatives');
    }
};
