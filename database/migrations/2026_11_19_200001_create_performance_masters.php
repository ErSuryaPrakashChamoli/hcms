<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity HP-8a — Performance masters: perspectives, initiative types and PIP reasons (one lookup table by
 * kind), units of measurement, and their links on KRAs, goals, key results and improvement plans. Key results gain a
 * due date (shown when performance.goals.kpi_due_date is on).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_lookups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24)->comment('perspective|initiative_type|pip_reason');
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_system')->default(false)->comment('seeded: can be deactivated, not deleted');
            $table->string('status', 16)->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'kind', 'code'], 'perf_lookups_tenant_kind_code_unique');
        });

        Schema::create('performance_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 24);
            $table->string('name', 64);
            $table->string('measure_type', 24)->nullable()->comment('how progress is calculated; null = the goal chooses');
            $table->decimal('default_target', 14, 2)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_admin')->default(true)->comment('false = added by an employee (visible to them and HR)');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('applicability')->nullable()->comment('rule conditions; empty = everyone');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'perf_units_tenant_code_unique');
        });

        Schema::table('kras', function (Blueprint $table) {
            $table->foreignId('perspective_id')->nullable()->constrained('performance_lookups')->nullOnDelete();
        });
        Schema::table('goals', function (Blueprint $table) {
            $table->foreignId('perspective_id')->nullable()->constrained('performance_lookups')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('performance_units')->nullOnDelete();
        });
        Schema::table('key_results', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->constrained('performance_units')->nullOnDelete();
            $table->date('due_date')->nullable();
        });
        Schema::table('improvement_plans', function (Blueprint $table) {
            $table->json('reason_codes')->nullable()->comment('PIP reason lookup codes; reason stays the details');
        });
    }

    public function down(): void
    {
        Schema::table('improvement_plans', fn (Blueprint $table) => $table->dropColumn('reason_codes'));
        Schema::table('key_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
            $table->dropColumn('due_date');
        });
        Schema::table('goals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
            $table->dropConstrainedForeignId('perspective_id');
        });
        Schema::table('kras', fn (Blueprint $table) => $table->dropConstrainedForeignId('perspective_id'));
        Schema::dropIfExists('performance_units');
        Schema::dropIfExists('performance_lookups');
    }
};
