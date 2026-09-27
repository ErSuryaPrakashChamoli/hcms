<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->string('type', 32);
            $table->string('classification', 32)->default('other');
            $table->string('calculation_method', 16)->default('fixed');
            $table->text('formula')->nullable();
            $table->boolean('taxable')->default(true);
            $table->boolean('pf_applicable')->default(false);
            $table->boolean('esi_applicable')->default(true);
            $table->boolean('include_in_ctc')->default(true);
            $table->boolean('include_in_gross')->default(true);
            $table->boolean('is_recurring')->default(true);
            $table->boolean('is_proratable')->default(true);
            $table->boolean('is_arrear_eligible')->default(true);
            $table->boolean('is_statutory')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 32)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('salary_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('salary_structure_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_structure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->restrictOnDelete();
            $table->text('formula_override')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['salary_structure_id', 'salary_component_id'], 'structure_component_unique');
        });

        // Salary history (§70, §100): one effective-dated row per revision; sensitive.
        Schema::create('employee_salary_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_structure_id')->constrained()->restrictOnDelete();
            $table->decimal('ctc_annual', 14, 2);
            $table->char('currency', 3)->default('INR');
            $table->json('component_values')->nullable();
            $table->string('change_type', 32)->default('hire');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'effective_from'], 'salary_assignments_employee_from_idx');
        });

        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 32)->default('open');
            $table->timestamps();

            $table->unique(['company_id', 'year', 'month']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('draft');
            $table->json('totals')->nullable();
            $table->unsignedInteger('exception_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('calculated_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('payroll_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_salary_assignment_id')->nullable()->constrained('employee_salary_assignments')->nullOnDelete();
            $table->unsignedTinyInteger('days_in_period')->default(0);
            $table->decimal('paid_days', 5, 2)->default(0);
            $table->decimal('lop_days', 5, 2)->default(0);
            $table->decimal('gross', 14, 2)->default(0);
            $table->decimal('total_earnings', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('net_pay', 14, 2)->default(0);
            $table->decimal('employer_cost', 14, 2)->default(0);
            $table->decimal('taxable_earnings', 14, 2)->default(0);
            $table->string('status', 32)->default('ok');
            $table->json('exceptions')->nullable();
            $table->json('inputs')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('payroll_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('type', 32);
            $table->string('classification', 32)->nullable();
            $table->decimal('amount', 14, 2);
            $table->boolean('taxable')->default(false);
            $table->json('basis')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['payroll_entry_id', 'sort_order']);
        });

        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('name');
            $table->decimal('amount', 14, 2);
            $table->boolean('taxable')->default(true);
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'payroll_period_id', 'employee_id'], 'payroll_adjustments_period_employee_idx');
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_entry_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->json('snapshot');
            $table->timestamp('generated_at');
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('payroll_entry_lines');
        Schema::dropIfExists('payroll_entries');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('payroll_periods');
        Schema::dropIfExists('employee_salary_assignments');
        Schema::dropIfExists('salary_structure_components');
        Schema::dropIfExists('salary_structures');
        Schema::dropIfExists('salary_components');
    }
};
