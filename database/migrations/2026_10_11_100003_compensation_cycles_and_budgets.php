<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 11.3 — compensation budgets and bulk compensation cycles. Additive only.
 |
 | A budget is an amount of annualised CTC increase for a scope and period, in one currency. A cycle
 | (annual increment, promotion, market adjustment) proposes one compensation change per employee:
 | those changes are the cycle's lines (compensation_changes.compensation_cycle_id, unique per
 | employee), reviewed, approved and executed together under one operation id, all or nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compensation_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->foreignId('company_id')->constrained(indexName: 'comp_budgets_company_fk')->restrictOnDelete();
            $table->foreignId('organisation_node_id')->nullable()->constrained(indexName: 'comp_budgets_node_fk')->restrictOnDelete();
            $table->foreignId('business_unit_id')->nullable()->constrained(indexName: 'comp_budgets_business_unit_fk')->restrictOnDelete();
            $table->foreignId('division_id')->nullable()->constrained(indexName: 'comp_budgets_division_fk')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained(indexName: 'comp_budgets_department_fk')->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->constrained(indexName: 'comp_budgets_team_fk')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained(indexName: 'comp_budgets_location_fk')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->char('currency', 3);
            // One declared basis: the annualised CTC increase of the changes charged to the budget.
            $table->string('basis', 32)->default('annual_ctc_increase');
            $table->decimal('amount', 15, 2);
            // Running total of the increases charged by approvals (the budget row is the lock).
            $table->decimal('charged_amount', 15, 2)->default(0);
            $table->string('status', 16)->default('draft');
            $table->foreignId('workforce_budget_id')->nullable()->constrained(indexName: 'comp_budgets_workforce_budget_fk')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('prepared_by')->constrained('users', indexName: 'comp_budgets_prepared_by_fk')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users', indexName: 'comp_budgets_approved_by_fk')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'comp_budgets_code_unique');
            $table->index(['tenant_id', 'company_id', 'status'], 'comp_budgets_company_index');
        });

        Schema::create('compensation_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('cycle_type', 32);
            $table->foreignId('company_id')->constrained(indexName: 'comp_cycles_company_fk')->restrictOnDelete();
            $table->foreignId('organisation_node_id')->nullable()->constrained(indexName: 'comp_cycles_node_fk')->restrictOnDelete();
            $table->foreignId('business_unit_id')->nullable()->constrained(indexName: 'comp_cycles_business_unit_fk')->restrictOnDelete();
            $table->foreignId('division_id')->nullable()->constrained(indexName: 'comp_cycles_division_fk')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained(indexName: 'comp_cycles_department_fk')->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->constrained(indexName: 'comp_cycles_team_fk')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained(indexName: 'comp_cycles_location_fk')->restrictOnDelete();
            $table->date('effective_from');
            $table->string('status', 16)->default('draft');
            $table->foreignId('compensation_budget_id')->nullable()->constrained(indexName: 'comp_cycles_budget_fk')->restrictOnDelete();
            $table->foreignId('performance_cycle_id')->nullable()->constrained(indexName: 'comp_cycles_performance_cycle_fk')->restrictOnDelete();
            // Deterministic proposal rules: default increase % and an optional % per finalized rating label.
            $table->decimal('default_increase_percent', 6, 2)->default(0);
            $table->json('rating_increase_percent')->nullable();
            $table->unsignedInteger('employee_count')->default(0);
            $table->string('snapshot_checksum', 64)->nullable();
            $table->string('operation_id', 26)->nullable();
            $table->foreignId('prepared_by')->constrained('users', indexName: 'comp_cycles_prepared_by_fk')->restrictOnDelete();
            $table->timestamp('populated_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users', indexName: 'comp_cycles_reviewed_by_fk')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users', indexName: 'comp_cycles_approved_by_fk')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users', indexName: 'comp_cycles_executed_by_fk')->restrictOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users', indexName: 'comp_cycles_closed_by_fk')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'comp_cycles_code_unique');
            $table->index(['tenant_id', 'status'], 'comp_cycles_status_index');
        });

        Schema::table('compensation_changes', function (Blueprint $table) {
            $table->foreignId('compensation_cycle_id')->nullable()->after('source')->constrained(indexName: 'comp_changes_cycle_fk')->restrictOnDelete();
            $table->foreignId('compensation_budget_id')->nullable()->after('compensation_cycle_id')->constrained(indexName: 'comp_changes_budget_fk')->restrictOnDelete();
            // The finalized performance outcome a cycle line was prefilled from (information only).
            $table->string('performance_label', 64)->nullable()->after('compensation_budget_id');
            $table->decimal('increase_percent', 6, 2)->nullable()->after('performance_label');
            $table->unique(['compensation_cycle_id', 'employee_id'], 'comp_changes_cycle_employee_unique');
            $table->index(['tenant_id', 'compensation_budget_id', 'status'], 'comp_changes_budget_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE compensation_budgets ADD CONSTRAINT comp_budgets_amount_check CHECK (amount >= 0 AND period_end >= period_start AND charged_amount <= amount)');
            DB::statement("ALTER TABLE compensation_budgets ADD CONSTRAINT comp_budgets_status_check CHECK (status IN ('draft', 'approved', 'closed'))");
            DB::statement('ALTER TABLE compensation_budgets ADD CONSTRAINT comp_budgets_sod_check CHECK (approved_by IS NULL OR approved_by <> prepared_by)');
            DB::statement("ALTER TABLE compensation_cycles ADD CONSTRAINT comp_cycles_status_check CHECK (status IN ('draft', 'submitted', 'under_review', 'approved', 'executed', 'rejected', 'cancelled'))");
            DB::statement('ALTER TABLE compensation_cycles ADD CONSTRAINT comp_cycles_sod_check CHECK ('
                .'(reviewed_by IS NULL OR reviewed_by <> prepared_by)'
                .' AND (approved_by IS NULL OR (approved_by <> prepared_by AND (reviewed_by IS NULL OR approved_by <> reviewed_by)))'
                .' AND (executed_by IS NULL OR (executed_by <> prepared_by AND (reviewed_by IS NULL OR executed_by <> reviewed_by) AND (approved_by IS NULL OR executed_by <> approved_by))))');
            DB::statement('ALTER TABLE compensation_cycles ADD CONSTRAINT comp_cycles_percent_check CHECK (default_increase_percent >= -100 AND default_increase_percent <= 1000)');
        }
    }

    public function down(): void
    {
        Schema::table('compensation_changes', function (Blueprint $table) {
            $table->dropForeign('comp_changes_cycle_fk');
            $table->dropForeign('comp_changes_budget_fk');
            $table->dropUnique('comp_changes_cycle_employee_unique');
            $table->dropIndex('comp_changes_budget_index');
        });
        Schema::table('compensation_changes', function (Blueprint $table) {
            $table->dropColumn(['compensation_cycle_id', 'compensation_budget_id', 'performance_label', 'increase_percent']);
        });
        Schema::dropIfExists('compensation_cycles');
        Schema::dropIfExists('compensation_budgets');
    }
};
