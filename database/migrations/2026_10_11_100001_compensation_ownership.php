<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 11.1 — Compensation becomes the owner of employee compensation assignment. Additive only.
 |
 | employee_salary_assignments stays the single canonical, effective-dated assignment history (no
 | parallel table). It gains lineage (the approved compensation change that wrote the row), approval
 | metadata, pay frequency, a variable target and a status: a row is never deleted; a same-date
 | correction supersedes it and a cancelled scheduled change cancels it, both kept as history. Only
 | active rows form the canonical timeline; active_key (1 while active, null otherwise) makes "one
 | active row per employee per start date" a database rule.
 |
 | compensation_changes holds every proposed change: proposer → reviewer → approver → executor, four
 | different people (service rule plus MySQL CHECK constraints). User foreign keys are RESTRICT so the
 | CHECK constraints are allowed on MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compensation_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 26);
            $table->foreignId('employee_id')->constrained(indexName: 'comp_changes_employee_fk')->cascadeOnDelete();
            $table->string('change_type', 32);
            $table->string('source', 32)->default('manual');
            $table->string('status', 16)->default('draft');
            $table->date('effective_from');
            // Proposed compensation.
            $table->foreignId('salary_structure_id')->constrained(indexName: 'comp_changes_structure_fk')->restrictOnDelete();
            $table->decimal('ctc_annual', 14, 2);
            $table->char('currency', 3);
            $table->string('pay_frequency', 16)->default('monthly');
            $table->json('component_values')->nullable();
            $table->decimal('variable_target_annual', 14, 2)->nullable();
            // Compensation in force the day before the effective date when the change was submitted.
            $table->foreignId('previous_assignment_id')->nullable()->constrained('employee_salary_assignments', indexName: 'comp_changes_previous_fk')->restrictOnDelete();
            $table->decimal('previous_ctc_annual', 14, 2)->nullable();
            $table->char('previous_currency', 3)->nullable();
            $table->foreignId('previous_salary_structure_id')->nullable()->constrained('salary_structures', indexName: 'comp_changes_prev_structure_fk')->restrictOnDelete();
            $table->json('previous_component_values')->nullable();
            // Career references (informational: a compensation change never moves anyone).
            $table->foreignId('from_grade_id')->nullable()->constrained('grades', indexName: 'comp_changes_from_grade_fk')->restrictOnDelete();
            $table->foreignId('to_grade_id')->nullable()->constrained('grades', indexName: 'comp_changes_to_grade_fk')->restrictOnDelete();
            $table->foreignId('from_position_id')->nullable()->constrained('positions', indexName: 'comp_changes_from_position_fk')->restrictOnDelete();
            $table->foreignId('to_position_id')->nullable()->constrained('positions', indexName: 'comp_changes_to_position_fk')->restrictOnDelete();
            $table->text('reason');
            $table->text('internal_notes')->nullable();
            // Separation of duties.
            $table->foreignId('proposed_by')->constrained('users', indexName: 'comp_changes_proposed_by_fk')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users', indexName: 'comp_changes_reviewed_by_fk')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users', indexName: 'comp_changes_approved_by_fk')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users', indexName: 'comp_changes_rejected_by_fk')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('scheduled_by')->nullable()->constrained('users', indexName: 'comp_changes_scheduled_by_fk')->restrictOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->foreignId('effected_by')->nullable()->constrained('users', indexName: 'comp_changes_effected_by_fk')->restrictOnDelete();
            $table->timestamp('effective_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users', indexName: 'comp_changes_cancelled_by_fk')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            // The canonical assignment row this change wrote (set when scheduled).
            $table->foreignId('employee_salary_assignment_id')->nullable()->constrained(indexName: 'comp_changes_assignment_fk')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'reference'], 'comp_changes_reference_unique');
            $table->index(['tenant_id', 'status', 'effective_from'], 'comp_changes_status_index');
            $table->index(['tenant_id', 'employee_id', 'status'], 'comp_changes_employee_status_index');
        });

        Schema::table('employee_salary_assignments', function (Blueprint $table) {
            $table->foreignId('compensation_change_id')->nullable()->after('employee_id')->constrained(indexName: 'salary_assignments_change_fk')->restrictOnDelete();
            $table->string('status', 16)->default('active')->after('change_type');
            $table->unsignedTinyInteger('active_key')->nullable()->after('status');
            $table->foreignId('superseded_by_id')->nullable()->after('active_key')->constrained('employee_salary_assignments', indexName: 'salary_assignments_superseded_by_fk')->restrictOnDelete();
            $table->string('pay_frequency', 16)->default('monthly')->after('currency');
            $table->decimal('variable_target_annual', 14, 2)->nullable()->after('pay_frequency');
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users', indexName: 'salary_assignments_approved_by_fk')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        // Every existing row is part of the canonical timeline (Salaries::assign never left two rows
        // starting on the same day for one employee).
        DB::table('employee_salary_assignments')->update(['active_key' => 1]);

        Schema::table('employee_salary_assignments', function (Blueprint $table) {
            $table->unique(['employee_id', 'effective_from', 'active_key'], 'salary_assignments_active_start_unique');
            $table->index(['tenant_id', 'employee_id', 'status', 'effective_from'], 'salary_assignments_status_index');
        });
        // MySQL drops the employee foreign key's implicit index once the unique key above can serve it; a
        // database that was rolled back and re-migrated has an explicit copy instead. Drop it so every
        // database ends with the same indexes.
        if (DB::getDriverName() === 'mysql' && Schema::hasIndex('employee_salary_assignments', 'employee_salary_assignments_employee_id_foreign')) {
            Schema::table('employee_salary_assignments', fn (Blueprint $table) => $table->dropIndex('employee_salary_assignments_employee_id_foreign'));
        }

        // MySQL 8 enforces CHECK constraints; SQLite tests rely on the model guards and services.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE employee_salary_assignments ADD CONSTRAINT salary_assignments_ctc_check CHECK (ctc_annual > 0 AND (variable_target_annual IS NULL OR variable_target_annual >= 0))');
            DB::statement('ALTER TABLE employee_salary_assignments ADD CONSTRAINT salary_assignments_dates_check CHECK (effective_to IS NULL OR effective_to >= effective_from)');
            DB::statement("ALTER TABLE employee_salary_assignments ADD CONSTRAINT salary_assignments_status_check CHECK ((status = 'active' AND active_key = 1) OR (status IN ('superseded', 'cancelled') AND active_key IS NULL))");
            DB::statement('ALTER TABLE compensation_changes ADD CONSTRAINT comp_changes_amount_check CHECK (ctc_annual > 0 AND (variable_target_annual IS NULL OR variable_target_annual >= 0))');
            DB::statement("ALTER TABLE compensation_changes ADD CONSTRAINT comp_changes_status_check CHECK (status IN ('draft', 'submitted', 'under_review', 'approved', 'scheduled', 'effective', 'rejected', 'cancelled'))");
            DB::statement('ALTER TABLE compensation_changes ADD CONSTRAINT comp_changes_sod_check CHECK ('
                .'(reviewed_by IS NULL OR reviewed_by <> proposed_by)'
                .' AND (approved_by IS NULL OR (approved_by <> proposed_by AND (reviewed_by IS NULL OR approved_by <> reviewed_by)))'
                .' AND (rejected_by IS NULL OR rejected_by <> proposed_by)'
                .' AND (scheduled_by IS NULL OR (scheduled_by <> proposed_by AND (reviewed_by IS NULL OR scheduled_by <> reviewed_by) AND (approved_by IS NULL OR scheduled_by <> approved_by)))'
                .' AND (effected_by IS NULL OR (effected_by <> proposed_by AND (reviewed_by IS NULL OR effected_by <> reviewed_by) AND (approved_by IS NULL OR effected_by <> approved_by))))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE employee_salary_assignments DROP CHECK salary_assignments_ctc_check');
            DB::statement('ALTER TABLE employee_salary_assignments DROP CHECK salary_assignments_dates_check');
            DB::statement('ALTER TABLE employee_salary_assignments DROP CHECK salary_assignments_status_check');
        }
        Schema::table('employee_salary_assignments', function (Blueprint $table) {
            // MySQL silently dropped the employee foreign key's implicit index when the wider unique key
            // below took over that role; give it back (same name) before the unique key goes.
            if (DB::getDriverName() === 'mysql' && ! Schema::hasIndex('employee_salary_assignments', 'employee_salary_assignments_employee_id_foreign')) {
                $table->index('employee_id', 'employee_salary_assignments_employee_id_foreign');
            }
            $table->dropForeign('salary_assignments_change_fk');
            $table->dropForeign('salary_assignments_superseded_by_fk');
            $table->dropForeign('salary_assignments_approved_by_fk');
            $table->dropUnique('salary_assignments_active_start_unique');
            $table->dropIndex('salary_assignments_status_index');
        });
        Schema::table('employee_salary_assignments', function (Blueprint $table) {
            $table->dropColumn(['compensation_change_id', 'status', 'active_key', 'superseded_by_id', 'pay_frequency', 'variable_target_annual', 'approved_by', 'approved_at']);
        });
        Schema::dropIfExists('compensation_changes');
    }
};
