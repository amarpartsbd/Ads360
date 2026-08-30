<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing plans and their fee rules (spec §36).
 *
 * A plan is a named set of rules. Which plan applies is decided by scope:
 * platform default, then tenant, then organization — the most specific wins.
 * That is the whole hierarchy, expressed as data rather than as branches in
 * code, so adding a client override never means changing the engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_plans', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->string('name');
            $table->string('description')->nullable();

            // PLATFORM | TENANT | ORGANIZATION
            $table->string('scope', 32)->index();

            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('currency', 3);
            $table->boolean('is_active')->default(true);

            // Only one plan may be the platform fallback.
            $table->boolean('is_default')->default(false);

            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['scope', 'is_active']);
            $table->index(['organization_id', 'is_active']);
        });

        /*
         * A plan must name exactly what it applies to. A TENANT plan without a
         * tenant, or a PLATFORM plan with one, is not a thing the resolver
         * could ever match — better rejected on write than ignored on read.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE pricing_plans
            ADD CONSTRAINT pricing_plans_scope_matches_owner
            CHECK (
                (scope = 'PLATFORM' AND tenant_id IS NULL AND organization_id IS NULL)
                OR (scope = 'TENANT' AND tenant_id IS NOT NULL AND organization_id IS NULL)
                OR (scope = 'ORGANIZATION' AND organization_id IS NOT NULL)
            )
        SQL);

        // Exactly one active platform default, so resolution always terminates
        // somewhere well defined.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX pricing_plans_single_default
            ON pricing_plans ((is_default))
            WHERE is_default = true AND is_active = true
        SQL);

        // One active plan per tenant and per organization.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX pricing_plans_one_active_per_tenant
            ON pricing_plans (tenant_id)
            WHERE is_active = true AND scope = 'TENANT'
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX pricing_plans_one_active_per_organization
            ON pricing_plans (organization_id)
            WHERE is_active = true AND scope = 'ORGANIZATION'
        SQL);

        Schema::create('pricing_rules', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('pricing_plan_id')->constrained()->cascadeOnDelete();

            // What the rule charges for: PLATFORM_FEE, MANAGEMENT_FEE,
            // CAMPAIGN_SETUP_FEE, CURRENCY_MARKUP, TAX, SUBSCRIPTION.
            $table->string('fee_type', 48)->index();

            // PERCENTAGE | FIXED
            $table->string('calculation', 16);

            // A percentage as a decimal string ("7.5000" = 7.5%). Never a float.
            $table->decimal('percentage', 9, 4)->nullable();

            // A fixed amount in minor units of the plan's currency.
            $table->bigInteger('fixed_amount')->nullable();

            // Bounds on the computed fee, both optional.
            $table->bigInteger('minimum_amount')->nullable();
            $table->bigInteger('maximum_amount')->nullable();

            // Applies only above this transaction size, so tiered pricing needs
            // no special case in the engine.
            $table->bigInteger('applies_from_amount')->default(0);

            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['pricing_plan_id', 'fee_type', 'is_active']);
        });

        /*
         * A percentage rule needs a percentage and a fixed rule needs an
         * amount. A rule with neither would silently charge nothing.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE pricing_rules
            ADD CONSTRAINT pricing_rules_calculation_has_value
            CHECK (
                (calculation = 'PERCENTAGE' AND percentage IS NOT NULL AND percentage >= 0)
                OR (calculation = 'FIXED' AND fixed_amount IS NOT NULL AND fixed_amount >= 0)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pricing_rules
            ADD CONSTRAINT pricing_rules_sane_bounds
            CHECK (
                (minimum_amount IS NULL OR minimum_amount >= 0)
                AND (maximum_amount IS NULL OR maximum_amount >= 0)
                AND (
                    minimum_amount IS NULL
                    OR maximum_amount IS NULL
                    OR maximum_amount >= minimum_amount
                )
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('pricing_plans');
    }
};
