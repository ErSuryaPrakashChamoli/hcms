<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rating_scales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->json('levels');
            $table->boolean('is_default')->default(false);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->string('category', 32)->default('core');
            $table->text('description')->nullable();
            $table->json('indicators')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('kras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->string('category', 64)->nullable();
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('default_weight')->default(0);
            $table->json('kpis')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('performance_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->string('type', 32)->default('annual');
            $table->date('period_start');
            $table->date('period_end');
            $table->foreignId('rating_scale_id')->constrained()->restrictOnDelete();
            $table->json('stages');
            $table->json('weights');
            $table->json('settings')->nullable();
            $table->json('competency_ids')->nullable();
            $table->json('eligibility')->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('current_stage', 32)->nullable();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('level', 32)->default('employee');
            $table->foreignId('employee_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('organisation_node_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('goals')->nullOnDelete();
            $table->foreignId('performance_cycle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('kra_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16)->default('goal');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('measure_type', 16)->default('percentage');
            $table->decimal('start_value', 14, 2)->default(0);
            $table->decimal('target_value', 14, 2)->default(100);
            $table->decimal('current_value', 14, 2)->default(0);
            $table->string('unit', 32)->nullable();
            $table->unsignedTinyInteger('weight')->default(0);
            $table->decimal('progress', 5, 2)->default(0);
            $table->string('status', 32)->default('draft');
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status']);
            $table->index(['tenant_id', 'parent_id']);
        });

        Schema::create('key_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('measure_type', 16)->default('percentage');
            $table->decimal('start_value', 14, 2)->default(0);
            $table->decimal('target_value', 14, 2)->default(100);
            $table->decimal('current_value', 14, 2)->default(0);
            $table->string('unit', 32)->nullable();
            $table->unsignedTinyInteger('weight')->default(0);
            $table->decimal('progress', 5, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('goal_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('key_result_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('value', 14, 2)->nullable();
            $table->decimal('progress', 5, 2);
            $table->string('confidence', 16)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('appraisals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 32)->default('pending');
            $table->decimal('goal_score', 5, 2)->nullable();
            $table->decimal('competency_score', 5, 2)->nullable();
            $table->decimal('self_rating', 4, 2)->nullable();
            $table->decimal('manager_rating', 4, 2)->nullable();
            $table->decimal('peer_rating', 4, 2)->nullable();
            $table->decimal('computed_rating', 4, 2)->nullable();
            $table->decimal('calibrated_rating', 4, 2)->nullable();
            $table->decimal('final_rating', 4, 2)->nullable();
            $table->string('final_label')->nullable();
            $table->text('calibration_note')->nullable();
            $table->text('manager_summary')->nullable();
            $table->boolean('promotion_recommended')->default(false);
            $table->boolean('pip_recommended')->default(false);
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('employee_comment')->nullable();
            $table->timestamps();

            $table->unique(['performance_cycle_id', 'employee_id']);
        });

        Schema::create('appraisal_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->foreignId('reviewer_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 32)->default('pending');
            $table->decimal('overall_rating', 4, 2)->nullable();
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->text('comments')->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['appraisal_id', 'type']);
            $table->index(['tenant_id', 'reviewer_id', 'status']);
        });

        Schema::create('appraisal_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_review_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 16);
            $table->unsignedBigInteger('subject_id');
            $table->decimal('rating', 4, 2);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['appraisal_review_id', 'subject_type', 'subject_id'], 'appraisal_rating_subject_unique');
        });

        Schema::create('feedback_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('type', 16)->default('praise');
            $table->string('visibility', 16)->default('manager');
            $table->text('message');
            $table->foreignId('goal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('competency_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_from_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('feedback_entries')->nullOnDelete();
            $table->string('status', 16)->default('given');
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id']);
        });

        Schema::create('one_on_ones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manager_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamp('scheduled_at');
            $table->timestamp('held_at')->nullable();
            $table->text('agenda')->nullable();
            $table->text('notes')->nullable();
            $table->json('action_items')->nullable();
            $table->string('status', 16)->default('scheduled');
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'scheduled_at']);
        });

        Schema::create('improvement_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('appraisal_id')->nullable()->constrained()->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason');
            $table->json('objectives');
            $table->string('status', 16)->default('draft');
            $table->text('outcome')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('career_paths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->foreignId('job_family_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('career_path_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('career_path_id')->constrained()->cascadeOnDelete();
            $table->foreignId('designation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('required_skills')->nullable();
            $table->json('required_competencies')->nullable();
            $table->unsignedTinyInteger('typical_years')->nullable();
            $table->timestamps();

            $table->unique(['career_path_id', 'designation_id']);
        });

        Schema::create('career_aspirations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('career_path_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('target_designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->text('aspirations')->nullable();
            $table->json('interests')->nullable();
            $table->boolean('open_to_relocation')->default(false);
            $table->boolean('open_to_role_change')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['career_aspirations', 'career_path_steps', 'career_paths', 'improvement_plans', 'one_on_ones', 'feedback_entries', 'appraisal_ratings', 'appraisal_reviews', 'appraisals', 'goal_check_ins', 'key_results', 'goals', 'performance_cycles', 'kras', 'competencies', 'rating_scales'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
