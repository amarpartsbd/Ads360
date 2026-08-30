<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable security and business audit trail (spec §51).
 *
 * Rows are append-only: the model refuses updates and deletes, and no
 * application code is given a path to rewrite history. There is no `updated_at`
 * because an audit row is never updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            // Actor. Null user_id with actor_type SYSTEM covers scheduled jobs,
            // queue workers and provider webhooks.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 32)->default('USER');
            $table->string('actor_label')->nullable();

            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action')->index();
            $table->string('resource_type')->nullable();
            $table->string('resource_id')->nullable();

            // Secrets are redacted before they reach these columns.
            $table->jsonb('before_data')->nullable();
            $table->jsonb('after_data')->nullable();
            $table->jsonb('context')->default('{}');

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 64)->nullable()->index();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'organization_id', 'created_at']);
            $table->index(['resource_type', 'resource_id']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
