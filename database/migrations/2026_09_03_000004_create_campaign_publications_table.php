<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The record of every attempt to change something at a provider (Rule 17,
 * spec §98).
 *
 * Publishing is the one place where a retry can cost real money: a job that
 * runs twice must not create two campaigns, and a worker killed mid-request
 * must not leave the system unable to tell whether the provider acted.
 *
 * Two database rules carry that guarantee, and neither depends on the
 * application behaving correctly:
 *
 *   - `campaign_publications_unique_key` — a claim on an idempotency key
 *     succeeds for exactly one attempt. A second worker with the same key
 *     loses the insert and reads the winner's outcome instead of calling the
 *     provider.
 *   - `campaign_publications_one_success_per_operation` — at most one
 *     succeeded row per entity per operation, so even a fresh key cannot
 *     create a second copy of something that already exists.
 *
 * Rows are never deleted. They are the evidence of what was sent, when, and
 * what came back (spec §62).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_publications', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();

            // The thing being published: the campaign itself, an ad set or an
            // ad. Polymorphic because all three follow the same protocol.
            $table->string('publishable_type');
            $table->unsignedBigInteger('publishable_id');

            $table->string('provider', 32);
            $table->string('operation', 48);

            /*
             * Minted once, before the provider is called, and reused by every
             * retry of that same intent. It is what makes a retry safe: the
             * provider is asked to treat the second request as the first.
             */
            $table->string('idempotency_key', 64);

            $table->string('status', 32)->index();

            // What the provider called the thing it created.
            $table->string('provider_reference', 128)->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);

            // Client-safe text. Provider error codes stay in the logs.
            $table->string('last_error')->nullable();

            /*
             * The request as it was sent, minus anything sensitive. Kept so a
             * failed publish can be explained without replaying it.
             */
            $table->jsonb('request_snapshot')->default('{}');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['publishable_type', 'publishable_id']);
            $table->index(['campaign_id', 'status']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX campaign_publications_unique_key
            ON campaign_publications (idempotency_key)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX campaign_publications_one_success_per_operation
            ON campaign_publications (publishable_type, publishable_id, operation)
            WHERE status = 'SUCCEEDED'
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE campaign_publications
            ADD CONSTRAINT campaign_publications_succeeded_rows_have_a_reference
            CHECK (status <> 'SUCCEEDED' OR provider_reference IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_publications');
    }
};
