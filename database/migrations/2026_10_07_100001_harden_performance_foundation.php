<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 7 — Performance, Goals & Continuous Performance hardening. Additive only.
 |
 | templates / template versions : reusable, versioned review configuration; a launched cycle and
 |                                 each appraisal pin the version (sections, rating-scale snapshot,
 |                                 competency snapshot, workflow stages, weights, goal rules)
 | performance_check_ins         : recurring employee ↔ manager conversations
 | calibration_sessions / adjustments : calibration with immutable rating-change history
 | improvement_plan_checkpoints  : PIP checkpoints
 | development_needs             : boundary for a future Learning module
 | performance_reminder_logs     : one reminder per subject per day
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('performance_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_template_id')->constrained(indexName: 'perf_template_versions_template_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('sections');
            $table->foreignId('rating_scale_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('rating_scale_snapshot');
            $table->json('competency_snapshot');
            $table->json('workflow');
            $table->json('weights');
            $table->json('goal_rules');
            $table->string('checksum', 64);
            $table->string('status', 16)->default('published');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['performance_template_id', 'version'], 'perf_template_versions_unique');
        });

        Schema::table('performance_cycles', function (Blueprint $table) {
            $table->foreignId('performance_template_version_id')->nullable()->after('rating_scale_id')->constrained(indexName: 'perf_cycles_template_version_fk')->restrictOnDelete();
            $table->date('scheduled_for')->nullable()->after('current_stage');
            $table->timestamp('archived_at')->nullable()->after('closed_at');
        });

        Schema::table('appraisals', function (Blueprint $table) {
            $table->foreignId('performance_template_version_id')->nullable()->after('performance_cycle_id')->constrained(indexName: 'appraisals_template_version_fk')->restrictOnDelete();
            $table->timestamp('locked_at')->nullable()->after('acknowledged_at');
            $table->unsignedInteger('lock_version')->default(0)->after('locked_at');
        });

        Schema::table('competencies', function (Blueprint $table) {
            $table->string('level', 32)->nullable()->after('category');
            $table->decimal('weight', 5, 2)->nullable()->after('level');
            $table->date('effective_from')->nullable()->after('status');
            $table->date('effective_to')->nullable()->after('effective_from');
        });

        Schema::table('goals', function (Blueprint $table) {
            $table->string('source', 32)->default('manual')->after('status');
            $table->text('measurement')->nullable()->after('unit');
            $table->unsignedInteger('lock_version')->default(0)->after('is_locked');
        });

        Schema::table('goal_check_ins', function (Blueprint $table) {
            $table->decimal('previous_value', 14, 2)->nullable()->after('key_result_id');
            $table->decimal('previous_progress', 5, 2)->nullable()->after('value');
            $table->string('source', 32)->default('manual')->after('confidence');
            $table->text('measurement')->nullable()->after('source');
        });

        Schema::create('performance_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('performance_cycle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cadence', 16)->default('monthly');
            $table->date('period_date');
            $table->text('went_well')->nullable();
            $table->text('blockers')->nullable();
            $table->text('support_needed')->nullable();
            $table->text('priorities')->nullable();
            $table->json('goal_progress')->nullable();
            $table->text('employee_feedback')->nullable();
            $table->text('manager_feedback')->nullable();
            $table->json('actions')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'cadence', 'period_date'], 'perf_check_ins_period_unique');
            $table->index(['tenant_id', 'manager_id', 'status']);
        });

        Schema::table('one_on_ones', function (Blueprint $table) {
            $table->text('private_notes')->nullable()->after('notes');
        });

        Schema::table('feedback_entries', function (Blueprint $table) {
            $table->boolean('is_anonymous')->default(false)->after('visibility');
        });

        Schema::create('calibration_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_cycle_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->foreignId('facilitator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('participants')->nullable();
            $table->json('population')->nullable();
            $table->string('status', 16)->default('open');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('calibration_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calibration_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('appraisal_id')->constrained()->restrictOnDelete();
            $table->decimal('original_rating', 4, 2)->nullable();
            $table->decimal('previous_rating', 4, 2)->nullable();
            $table->decimal('adjusted_rating', 4, 2);
            $table->text('reason');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['appraisal_id', 'id']);
        });

        Schema::table('improvement_plans', function (Blueprint $table) {
            $table->text('closure_reason')->nullable()->after('outcome');
            $table->unsignedInteger('lock_version')->default(0)->after('closed_at');
        });

        Schema::create('improvement_plan_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('improvement_plan_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->date('due_date');
            $table->text('notes')->nullable();
            $table->text('outcome')->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['improvement_plan_id', 'due_date']);
        });

        Schema::create('development_needs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('competency_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 16)->default('medium');
            $table->string('status', 16)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status']);
        });

        Schema::create('performance_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reminder', 32);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->date('reminded_on');
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'reminder', 'subject_type', 'subject_id', 'reminded_on'], 'perf_reminder_logs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_reminder_logs');
        Schema::dropIfExists('development_needs');
        Schema::dropIfExists('improvement_plan_checkpoints');
        Schema::table('improvement_plans', fn (Blueprint $t) => $t->dropColumn(['closure_reason', 'lock_version']));
        Schema::dropIfExists('calibration_adjustments');
        Schema::dropIfExists('calibration_sessions');
        Schema::table('feedback_entries', fn (Blueprint $t) => $t->dropColumn('is_anonymous'));
        Schema::table('one_on_ones', fn (Blueprint $t) => $t->dropColumn('private_notes'));
        Schema::dropIfExists('performance_check_ins');
        Schema::table('goal_check_ins', fn (Blueprint $t) => $t->dropColumn(['previous_value', 'previous_progress', 'source', 'measurement']));
        Schema::table('goals', fn (Blueprint $t) => $t->dropColumn(['source', 'measurement', 'lock_version']));
        Schema::table('competencies', fn (Blueprint $t) => $t->dropColumn(['level', 'weight', 'effective_from', 'effective_to']));
        Schema::table('appraisals', function (Blueprint $t) {
            $t->dropConstrainedForeignId('performance_template_version_id');
            $t->dropColumn(['locked_at', 'lock_version']);
        });
        Schema::table('performance_cycles', function (Blueprint $t) {
            $t->dropConstrainedForeignId('performance_template_version_id');
            $t->dropColumn(['scheduled_for', 'archived_at']);
        });
        Schema::dropIfExists('performance_template_versions');
        Schema::dropIfExists('performance_templates');
    }
};
