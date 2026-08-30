<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role-based access control (spec §7).
 *
 * Authorization is decided on permissions, never on role names, so roles can be
 * renamed or added per tenant without touching application code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();          // e.g. campaigns.approve
            $table->string('group', 64)->index();      // e.g. campaigns
            $table->string('description')->nullable();

            // Privileged permissions require step-up authentication and are
            // eligible for maker-checker control (spec §9, §25).
            $table->boolean('is_privileged')->default(false);

            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            // Null for system roles shipped with the platform; set for roles a
            // tenant defines for itself.
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->string('scope', 32)->index();      // PLATFORM | TENANT | ORGANIZATION
            $table->string('description')->nullable();

            // System roles cannot be edited or deleted by tenant administrators.
            $table->boolean('is_system')->default(false);

            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('permission_role', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();

            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('role_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // A grant is scoped to one organization. Platform and tenant-wide
            // grants leave this null.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();

            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'organization_id']);
        });

        // Postgres treats NULLs as distinct in a unique index, so an unscoped
        // grant would otherwise be insertable twice. Two partial indexes cover
        // both shapes of grant.
        DB::statement(
            'CREATE UNIQUE INDEX role_user_scoped_unique ON role_user (role_id, user_id, organization_id)
             WHERE organization_id IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX role_user_unscoped_unique ON role_user (role_id, user_id)
             WHERE organization_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
