<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 6.5 — controlled parallel payroll cycle (§22–23): reference values supplied by the business
 | (its current payroll / portal expectations) compared with PeopleOS at employee × component and
 | statutory return-total level. Every difference needs a reason, a resolution and a reviewer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parallel_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->restrictOnDelete();
            $table->string('reference_source');
            $table->text('reference_description')->nullable();
            $table->string('reference_file_sha256', 64);
            $table->string('status', 16)->default('imported');
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('compared_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index(['payroll_run_id', 'status']);
        });

        Schema::create('parallel_payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parallel_payroll_run_id')->constrained()->restrictOnDelete();
            $table->string('level', 8);
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_code', 64)->nullable();
            $table->string('scope', 32)->nullable();
            $table->string('component', 64);
            $table->decimal('peopleos_value', 16, 2)->nullable();
            $table->decimal('reference_value', 16, 2)->nullable();
            $table->decimal('difference', 16, 2)->nullable();
            $table->string('status', 20);
            $table->text('reason')->nullable();
            $table->text('resolution')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['parallel_payroll_run_id', 'level', 'employee_code', 'scope', 'component'], 'parallel_lines_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parallel_payroll_lines');
        Schema::dropIfExists('parallel_payroll_runs');
    }
};
