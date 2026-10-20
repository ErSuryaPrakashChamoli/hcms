<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 10 — Workforce Planning, Position Management & Headcount Planning foundation. Additive only.
 |
 | A Position is organisational capacity (a seat), never an employee. Its definition is a series of
 | effective-dated, immutable position_versions; employees occupy it through their authoritative
 | employee_positions rows (new nullable position_id / fte). Plans, scenarios and budgets are
 | planning records only and never write live workforce, payroll or statutory data. "Unique while
 | active" rules use a nullable active_key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('title');
            $table->string('status', 16)->default('draft'); // lifecycle status of the latest version
            // Denormalised from the latest version for listing and organisation scoping.
            $table->foreignId('designation_id')->nullable()->constrained(indexName: 'positions_designation_fk')->nullOnDelete();
            $table->foreignId('organisation_node_id')->nullable()->constrained(indexName: 'positions_node_fk')->nullOnDelete();
            $table->foreignId('business_unit_id')->nullable()->constrained(indexName: 'positions_business_unit_fk')->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained(indexName: 'positions_division_fk')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained(indexName: 'positions_department_fk')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained(indexName: 'positions_team_fk')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained(indexName: 'positions_location_fk')->nullOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained(indexName: 'positions_establishment_fk')->nullOnDelete();
            $table->date('first_effective_from');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedBigInteger('source_plan_line_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'positions_created_by_fk')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'positions_code_unique');
            $table->index(['tenant_id', 'status'], 'positions_status_index');
            $table->index(['tenant_id', 'company_id', 'organisation_node_id'], 'positions_org_index');
            $table->index(['tenant_id', 'location_id'], 'positions_location_index');
        });

        Schema::create('position_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->constrained(indexName: 'position_versions_position_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('title');
            $table->foreignId('designation_id')->nullable()->constrained(indexName: 'position_versions_designation_fk')->nullOnDelete();
            $table->foreignId('job_family_id')->nullable()->constrained(indexName: 'position_versions_job_family_fk')->nullOnDelete();
            $table->foreignId('career_track_id')->nullable()->constrained(indexName: 'position_versions_track_fk')->nullOnDelete();
            $table->foreignId('company_id')->constrained(indexName: 'position_versions_company_fk')->restrictOnDelete();
            $table->foreignId('organisation_node_id')->nullable()->constrained(indexName: 'position_versions_node_fk')->nullOnDelete();
            $table->foreignId('business_unit_id')->nullable()->constrained(indexName: 'position_versions_business_unit_fk')->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained(indexName: 'position_versions_division_fk')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained(indexName: 'position_versions_department_fk')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained(indexName: 'position_versions_team_fk')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained(indexName: 'position_versions_location_fk')->nullOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained(indexName: 'position_versions_establishment_fk')->nullOnDelete();
            $table->foreignId('legal_entity_id')->nullable()->constrained(indexName: 'position_versions_legal_entity_fk')->nullOnDelete();
            $table->foreignId('employment_type_id')->nullable()->constrained(indexName: 'position_versions_employment_type_fk')->nullOnDelete();
            $table->string('worker_type', 32)->default('employee');
            $table->foreignId('grade_id')->nullable()->constrained(indexName: 'position_versions_grade_fk')->nullOnDelete();
            $table->foreignId('cost_centre_id')->nullable()->constrained(indexName: 'position_versions_cost_centre_fk')->nullOnDelete();
            $table->foreignId('parent_position_id')->nullable()->constrained('positions', indexName: 'position_versions_parent_fk')->restrictOnDelete();
            $table->string('occupancy_mode', 16)->default('single');
            $table->unsignedSmallInteger('headcount')->default(1);
            $table->decimal('fte', 5, 2)->default(1);
            $table->decimal('fte_capacity', 7, 2)->default(1);
            $table->decimal('standard_hours', 5, 2)->nullable();
            $table->string('change_type', 24);
            $table->text('reason')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'position_versions_created_by_fk')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'position_id', 'version'], 'position_versions_unique');
            $table->index(['tenant_id', 'position_id', 'effective_from'], 'position_versions_effective_index');
            $table->index(['tenant_id', 'status', 'effective_from'], 'position_versions_status_index');
            $table->index(['tenant_id', 'parent_position_id'], 'position_versions_parent_index');
            $table->index(['tenant_id', 'organisation_node_id', 'designation_id'], 'position_versions_org_index');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->foreign('current_version_id', 'positions_current_version_fk')->references('id')->on('position_versions')->nullOnDelete();
        });

        Schema::create('position_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->constrained(indexName: 'position_change_requests_position_fk')->restrictOnDelete();
            $table->json('changes');
            $table->json('categories');
            $table->date('effective_from');
            $table->string('status', 16)->default('pending');
            $table->text('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users', indexName: 'position_change_requests_requester_fk')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users', indexName: 'position_change_requests_decider_fk')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('applied_version_id')->nullable()->constrained('position_versions', indexName: 'position_change_requests_version_fk')->nullOnDelete();
            $table->unsignedBigInteger('workflow_instance_id')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'position_id', 'status'], 'position_change_requests_lookup');
        });

        Schema::table('employee_positions', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable()->after('cost_centre_id')->constrained('positions', indexName: 'employee_positions_position_fk')->nullOnDelete();
            $table->decimal('fte', 4, 2)->nullable()->after('position_id');
            $table->index(['tenant_id', 'position_id', 'effective_from'], 'employee_positions_position_index');
        });

        Schema::create('workforce_scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('assumptions')->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'workforce_scenarios_created_by_fk')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users', indexName: 'workforce_scenarios_approved_by_fk')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'workforce_scenarios_code_unique');
        });

        Schema::create('workforce_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->foreignId('company_id')->constrained(indexName: 'workforce_plans_company_fk')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->nullable()->constrained(indexName: 'workforce_plans_legal_entity_fk')->nullOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained(indexName: 'workforce_plans_establishment_fk')->nullOnDelete();
            $table->foreignId('organisation_node_id')->nullable()->constrained(indexName: 'workforce_plans_node_fk')->nullOnDelete();
            // Derived from the organisation node, for fail-closed organisation scoping.
            $table->foreignId('business_unit_id')->nullable()->constrained(indexName: 'workforce_plans_business_unit_fk')->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained(indexName: 'workforce_plans_division_fk')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained(indexName: 'workforce_plans_department_fk')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained(indexName: 'workforce_plans_team_fk')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained(indexName: 'workforce_plans_location_fk')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users', indexName: 'workforce_plans_owner_fk')->nullOnDelete();
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'workforce_plans_created_by_fk')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'workforce_plans_code_unique');
            $table->index(['tenant_id', 'company_id', 'organisation_node_id'], 'workforce_plans_scope_index');
        });

        Schema::create('workforce_plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workforce_plan_id')->constrained(indexName: 'workforce_plan_versions_plan_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('workforce_scenario_id')->nullable()->constrained(indexName: 'workforce_plan_versions_scenario_fk')->nullOnDelete();
            $table->string('period_type', 16);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('currency', 3);
            $table->string('status', 16)->default('draft');
            $table->string('active_key', 64)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users', indexName: 'workforce_plan_versions_submitter_fk')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users', indexName: 'workforce_plan_versions_reviewer_fk')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users', indexName: 'workforce_plan_versions_approver_fk')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('supersedes_version_id')->nullable()->constrained('workforce_plan_versions', indexName: 'workforce_plan_versions_supersedes_fk')->nullOnDelete();
            $table->unsignedBigInteger('workflow_instance_id')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'workforce_plan_versions_created_by_fk')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'workforce_plan_id', 'version'], 'workforce_plan_versions_unique');
            $table->unique(['tenant_id', 'active_key'], 'workforce_plan_versions_active_unique');
            $table->index(['tenant_id', 'status'], 'workforce_plan_versions_status_index');
        });

        Schema::table('workforce_plans', function (Blueprint $table) {
            $table->foreign('active_version_id', 'workforce_plans_active_version_fk')->references('id')->on('workforce_plan_versions')->nullOnDelete();
        });

        Schema::create('workforce_plan_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workforce_plan_version_id')->constrained(indexName: 'workforce_plan_lines_version_fk')->cascadeOnDelete();
            $table->string('movement_type', 24);
            $table->foreignId('position_id')->nullable()->constrained(indexName: 'workforce_plan_lines_position_fk')->nullOnDelete();
            $table->foreignId('organisation_node_id')->nullable()->constrained(indexName: 'workforce_plan_lines_node_fk')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained(indexName: 'workforce_plan_lines_location_fk')->nullOnDelete();
            $table->foreignId('job_family_id')->nullable()->constrained(indexName: 'workforce_plan_lines_job_family_fk')->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained(indexName: 'workforce_plan_lines_designation_fk')->nullOnDelete();
            $table->foreignId('grade_id')->nullable()->constrained(indexName: 'workforce_plan_lines_grade_fk')->nullOnDelete();
            $table->foreignId('employment_type_id')->nullable()->constrained(indexName: 'workforce_plan_lines_employment_type_fk')->nullOnDelete();
            $table->foreignId('cost_centre_id')->nullable()->constrained(indexName: 'workforce_plan_lines_cost_centre_fk')->nullOnDelete();
            $table->unsignedInteger('headcount');
            $table->decimal('fte', 8, 2);
            $table->decimal('planned_cost', 15, 2)->nullable();
            $table->string('cost_basis', 32)->nullable();
            $table->date('effective_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_position_id')->nullable()->constrained('positions', indexName: 'workforce_plan_lines_created_position_fk')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'workforce_plan_version_id', 'movement_type'], 'workforce_plan_lines_version_index');
            $table->index(['tenant_id', 'effective_date'], 'workforce_plan_lines_date_index');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->foreign('source_plan_line_id', 'positions_source_plan_line_fk')->references('id')->on('workforce_plan_lines')->nullOnDelete();
        });

        Schema::create('workforce_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workforce_plan_version_id')->nullable()->constrained(indexName: 'workforce_budgets_plan_version_fk')->nullOnDelete();
            $table->foreignId('company_id')->constrained(indexName: 'workforce_budgets_company_fk')->restrictOnDelete();
            $table->foreignId('organisation_node_id')->nullable()->constrained(indexName: 'workforce_budgets_node_fk')->nullOnDelete();
            $table->foreignId('business_unit_id')->nullable()->constrained(indexName: 'workforce_budgets_business_unit_fk')->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained(indexName: 'workforce_budgets_division_fk')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained(indexName: 'workforce_budgets_department_fk')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained(indexName: 'workforce_budgets_team_fk')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained(indexName: 'workforce_budgets_location_fk')->nullOnDelete();
            $table->foreignId('cost_centre_id')->nullable()->constrained(indexName: 'workforce_budgets_cost_centre_fk')->nullOnDelete();
            $table->string('name');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('currency', 3);
            $table->string('cost_basis', 32);
            $table->decimal('amount', 15, 2);
            $table->string('status', 16)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users', indexName: 'workforce_budgets_approved_by_fk')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'workforce_budgets_created_by_fk')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'company_id', 'period_start'], 'workforce_budgets_scope_index');
        });

        Schema::create('workforce_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reminder', 32);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->date('reminded_on');
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'reminder', 'subject_type', 'subject_id', 'reminded_on'], 'workforce_reminder_logs_unique');
        });

        // MySQL 8 enforces CHECK constraints; SQLite tests rely on the model guards.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE position_versions ADD CONSTRAINT position_versions_headcount_check CHECK (headcount >= 1)');
            DB::statement('ALTER TABLE position_versions ADD CONSTRAINT position_versions_fte_check CHECK (fte > 0 AND fte_capacity > 0)');
            DB::statement('ALTER TABLE position_versions ADD CONSTRAINT position_versions_parent_check CHECK (parent_position_id IS NULL OR parent_position_id <> position_id)');
            DB::statement('ALTER TABLE employee_positions ADD CONSTRAINT employee_positions_fte_check CHECK (fte IS NULL OR fte > 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE employee_positions DROP CHECK employee_positions_fte_check');
        }
        Schema::dropIfExists('workforce_reminder_logs');
        Schema::dropIfExists('workforce_budgets');
        Schema::table('positions', fn (Blueprint $t) => $t->dropForeign('positions_source_plan_line_fk'));
        Schema::dropIfExists('workforce_plan_lines');
        Schema::table('workforce_plans', fn (Blueprint $t) => $t->dropForeign('workforce_plans_active_version_fk'));
        Schema::dropIfExists('workforce_plan_versions');
        Schema::dropIfExists('workforce_plans');
        Schema::dropIfExists('workforce_scenarios');
        Schema::table('employee_positions', function (Blueprint $t) {
            $t->dropForeign('employee_positions_position_fk');
            $t->dropIndex('employee_positions_position_index');
            $t->dropColumn(['position_id', 'fte']);
        });
        Schema::dropIfExists('position_change_requests');
        Schema::table('positions', fn (Blueprint $t) => $t->dropForeign('positions_current_version_fk'));
        Schema::dropIfExists('position_versions');
        Schema::dropIfExists('positions');
    }
};
