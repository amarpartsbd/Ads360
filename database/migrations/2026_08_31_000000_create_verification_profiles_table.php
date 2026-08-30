<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business verification profiles (spec §11).
 *
 * One profile per organization. It holds what the client declared about their
 * business; the documents that evidence it live in `verification_documents`,
 * and the decisions taken on it in `verification_reviews`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_profiles', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            /*
             * Declared business details.
             *
             * Nullable because a profile starts life as a draft: a client
             * uploads documents and fills the form over more than one sitting.
             * Completeness is not left to application code, though — the check
             * constraint at the end of this migration makes it impossible for a
             * row to leave NOT_SUBMITTED without them.
             */
            $table->string('legal_business_name')->nullable();
            $table->string('trading_name')->nullable();
            $table->string('business_type', 64)->nullable();
            $table->string('website')->nullable();
            $table->string('facebook_page')->nullable();

            $table->string('contact_number', 32)->nullable();
            $table->string('business_email')->nullable();

            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 128)->nullable();
            $table->string('state', 128)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('country', 2)->nullable();

            $table->string('authorized_person_name')->nullable();
            $table->string('authorized_person_designation', 128)->nullable();
            $table->string('authorized_person_email')->nullable();
            $table->string('authorized_person_phone', 32)->nullable();

            // Registration identifiers. Held as strings: these are references,
            // never arithmetic, and leading zeros are significant.
            $table->string('trade_license_number', 64)->nullable();
            $table->string('tin', 64)->nullable();
            $table->string('bin_vat_number', 64)->nullable();

            // Expected monthly spend in minor units of the declared currency,
            // so it is never a float (spec §59).
            $table->bigInteger('expected_monthly_spend_minor')->nullable();
            $table->string('expected_monthly_spend_currency', 3)->nullable();
            $table->string('advertising_category', 128)->nullable();

            $table->string('status', 32)->index();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            // The reviewer's message to the client. Internal reasoning stays in
            // verification_reviews and is never shown to the client (spec §54).
            $table->text('client_message')->nullable();

            $table->timestamps();

            // A profile is the organization's single record of verification, so
            // resubmission updates it rather than creating a second one.
            $table->unique('organization_id');
            $table->index(['tenant_id', 'status']);

            // Drives the compliance queue: oldest pending submission first.
            $table->index(['status', 'submitted_at']);
        });

        /*
         * A draft may be incomplete; anything a reviewer can see may not be.
         * Enforced here rather than only in the submission action, so no future
         * code path — a seeder, an import, a console command — can put an
         * incomplete submission into the compliance queue (spec §59).
         */
        DB::statement(<<<'SQL'
            ALTER TABLE verification_profiles
            ADD CONSTRAINT verification_profiles_complete_when_submitted
            CHECK (
                status = 'NOT_SUBMITTED'
                OR (
                    legal_business_name IS NOT NULL
                    AND business_type IS NOT NULL
                    AND contact_number IS NOT NULL
                    AND business_email IS NOT NULL
                    AND address_line_1 IS NOT NULL
                    AND city IS NOT NULL
                    AND country IS NOT NULL
                    AND authorized_person_name IS NOT NULL
                    AND authorized_person_designation IS NOT NULL
                    AND authorized_person_email IS NOT NULL
                    AND authorized_person_phone IS NOT NULL
                    AND submitted_at IS NOT NULL
                )
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_profiles');
    }
};
