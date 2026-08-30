<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices (spec §37).
 *
 * A finalised invoice is never edited. Correcting one means voiding it and
 * issuing a credit note that points back at it, so what the client was
 * originally sent remains reproducible (spec §62).
 *
 * The billing address is copied onto the invoice rather than joined from the
 * organization: an invoice must still render as it was issued even after the
 * client moves office.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('number', 32)->unique();

            // INVOICE | CREDIT_NOTE
            $table->string('kind', 16)->default('INVOICE')->index();

            $table->string('status', 32)->index();

            $table->string('currency', 3);
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('discount_total')->default(0);
            $table->bigInteger('total')->default(0);
            $table->bigInteger('amount_paid')->default(0);

            // Copied at issue time so the document stays reproducible.
            $table->string('billing_name');
            $table->text('billing_address')->nullable();
            $table->string('billing_email')->nullable();
            $table->string('tax_identifier', 64)->nullable();

            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();

            // A credit note names the invoice it corrects.
            $table->foreignId('corrects_invoice_id')->nullable()->constrained('invoices');

            $table->jsonb('pricing_snapshot')->nullable();
            $table->jsonb('metadata')->default('{}');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['tenant_id', 'issued_on']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE invoices
            ADD CONSTRAINT invoices_totals_consistent
            CHECK (
                subtotal >= 0
                AND tax_total >= 0
                AND discount_total >= 0
                AND amount_paid >= 0
                AND total = subtotal + tax_total - discount_total
            )
        SQL);

        /*
         * Anything a client has seen must carry the date it was issued on.
         * A draft need not.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE invoices
            ADD CONSTRAINT invoices_issued_has_date
            CHECK (status = 'DRAFT' OR issued_on IS NOT NULL)
        SQL);

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            $table->string('description');
            $table->string('fee_type', 48)->nullable();

            // Quantity in thousandths, so a partial month of management fee is
            // exact rather than a rounded float.
            $table->unsignedBigInteger('quantity_milli')->default(1000);
            $table->bigInteger('unit_amount');
            $table->bigInteger('line_total');
            $table->bigInteger('tax_amount')->default(0);

            $table->unsignedSmallInteger('position')->default(0);
            $table->jsonb('metadata')->default('{}');

            $table->timestamps();

            $table->index(['invoice_id', 'position']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE invoice_lines
            ADD CONSTRAINT invoice_lines_sane_amounts
            CHECK (quantity_milli > 0 AND tax_amount >= 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
