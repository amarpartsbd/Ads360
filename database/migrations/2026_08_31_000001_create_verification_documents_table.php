<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files submitted as evidence for a verification profile (spec §11, §55).
 *
 * The row records where the bytes are, never the bytes themselves. `path` is a
 * random object key on a private disk; it is not derivable from anything the
 * client supplied, and it is not a URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_documents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('verification_profile_id')->constrained()->cascadeOnDelete();

            $table->string('type', 64)->index();

            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_filename');
            $table->string('media_type', 128);
            $table->unsignedBigInteger('size_bytes');

            // SHA-256 of the stored bytes: lets a reviewer confirm a file has
            // not changed, and catches the same document uploaded twice.
            $table->string('checksum', 64)->index();

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->string('status', 32)->index();
            $table->string('review_note')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['verification_profile_id', 'type']);
            $table->index(['tenant_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_documents');
    }
};
