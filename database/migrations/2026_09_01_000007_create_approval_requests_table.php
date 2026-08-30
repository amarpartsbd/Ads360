<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maker-checker control for high-risk actions (spec §25).
 *
 * An action above its configured threshold does not take effect when it is
 * requested. It is recorded here, waits for the required number of approvals
 * from *other* people, and only then executes. The payload holds everything
 * needed to carry the action out later, so nothing has to be re-entered — and
 * so what was approved is exactly what runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            // WALLET_ADJUSTMENT | REFUND | EXCHANGE_RATE_CHANGE | ...
            $table->string('action_type', 64)->index();

            $table->string('summary');

            // Everything the executor needs. Held as data rather than as a
            // serialised closure so it can be read, audited and argued about.
            $table->jsonb('payload');

            // Recorded for the threshold rule and for the queue's own display.
            $table->bigInteger('amount')->nullable();
            $table->string('currency', 3)->nullable();

            $table->unsignedSmallInteger('required_approvals')->default(1);
            $table->unsignedSmallInteger('approvals_received')->default(0);

            $table->string('status', 32)->index();

            $table->foreignId('requested_by')->constrained('users');
            $table->text('request_reason')->nullable();

            $table->timestamp('executed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();

            // What the execution produced, so the request links to its outcome.
            $table->string('result_type')->nullable();
            $table->string('result_id')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['organization_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE approval_requests
            ADD CONSTRAINT approval_requests_sane_approvals
            CHECK (required_approvals >= 1 AND approvals_received >= 0)
        SQL);

        Schema::create('approval_decisions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users');

            $table->string('decision', 16);
            $table->text('note')->nullable();

            $table->timestamp('created_at')->useCurrent();

            /*
             * One vote per person per request. Without this, a single approver
             * could satisfy a two-approval threshold by clicking twice — which
             * is precisely the control maker-checker exists to provide.
             */
            $table->unique(['approval_request_id', 'approver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_requests');
    }
};
