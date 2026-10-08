<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 9 — Career, Talent & Succession foundation. Additive only.
 |
 | A "position" is a Designation (the canonical role), optionally narrowed to an organisation unit;
 | no seat or person structure is duplicated. "Unique while active" rules use a nullable active_key
 | (MySQL allows many NULLs in a unique index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('track_type', 32);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('career_path_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('career_path_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('name');
            $table->json('scope');
            $table->json('steps');
            $table->string('checksum', 64);
            $table->date('effective_from');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['career_path_id', 'version'], 'career_path_versions_unique');
        });

        Schema::table('career_paths', function (Blueprint $table) {
            $table->foreignId('career_track_id')->nullable()->after('job_family_id')->constrained(indexName: 'career_paths_track_fk')->nullOnDelete();
            $table->foreignId('business_unit_id')->nullable()->after('career_track_id')->constrained(indexName: 'career_paths_business_unit_fk')->nullOnDelete();
            $table->foreignId('organisation_node_id')->nullable()->after('business_unit_id')->constrained(indexName: 'career_paths_node_fk')->nullOnDelete();
            $table->date('effective_from')->nullable()->after('status');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->foreignId('current_version_id')->nullable()->after('effective_to')->constrained('career_path_versions', indexName: 'career_paths_current_version_fk')->nullOnDelete();
        });

        Schema::create('role_requirement_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('designation_id')->constrained()->restrictOnDelete();
            $table->foreignId('organisation_node_id')->nullable()->constrained(indexName: 'role_requirements_node_fk')->nullOnDelete();
            $table->string('scope_key', 32)->default('');
            $table->unsignedInteger('version');
            $table->json('skills');
            $table->json('competencies');
            $table->decimal('min_experience_years', 4, 1)->nullable();
            $table->json('certifications');
            $table->json('learning');
            $table->text('notes')->nullable();
            $table->string('checksum', 64);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'designation_id', 'scope_key', 'version'], 'role_requirements_unique');
            $table->index(['tenant_id', 'designation_id', 'effective_from'], 'role_requirements_lookup');
        });

        Schema::create('career_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('career_track_id')->nullable()->constrained()->nullOnDelete();
            $table->json('preferred_job_family_ids')->nullable();
            $table->json('preferred_location_ids')->nullable();
            $table->json('target_designation_ids')->nullable();
            $table->json('mobility')->nullable();
            $table->text('development_priorities')->nullable();
            $table->boolean('share_aspirations_with_manager')->default(false);
            $table->boolean('share_goals_with_manager')->default(true);
            $table->boolean('share_mobility_with_manager')->default(false);
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('career_aspiration_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('term', 16);
            $table->foreignId('target_designation_id')->nullable()->constrained('designations', indexName: 'aspiration_entries_designation_fk')->nullOnDelete();
            $table->foreignId('target_job_family_id')->nullable()->constrained('job_families', indexName: 'aspiration_entries_job_family_fk')->nullOnDelete();
            $table->foreignId('target_location_id')->nullable()->constrained('locations', indexName: 'aspiration_entries_location_fk')->nullOnDelete();
            $table->foreignId('career_track_id')->nullable()->constrained(indexName: 'aspiration_entries_track_fk')->nullOnDelete();
            $table->text('aspiration')->nullable();
            $table->string('direction')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('current');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'term', 'status'], 'aspiration_entries_lookup');
        });

        Schema::create('career_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('goal_type', 24);
            $table->foreignId('target_designation_id')->nullable()->constrained('designations', indexName: 'career_goals_designation_fk')->nullOnDelete();
            $table->foreignId('skill_id')->nullable()->constrained(indexName: 'career_goals_skill_fk')->nullOnDelete();
            $table->decimal('target_level', 5, 2)->nullable();
            $table->foreignId('competency_id')->nullable()->constrained(indexName: 'career_goals_competency_fk')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained(indexName: 'career_goals_course_fk')->nullOnDelete();
            $table->foreignId('learning_path_id')->nullable()->constrained(indexName: 'career_goals_path_fk')->nullOnDelete();
            $table->foreignId('development_plan_id')->nullable()->constrained(indexName: 'career_goals_plan_fk')->nullOnDelete();
            $table->date('target_date')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamp('closed_at')->nullable();
            $table->text('closure_note')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status']);
        });

        Schema::create('mobility_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('interest_type', 24);
            $table->foreignId('designation_id')->nullable()->constrained(indexName: 'mobility_interests_designation_fk')->nullOnDelete();
            $table->foreignId('job_family_id')->nullable()->constrained(indexName: 'mobility_interests_job_family_fk')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained(indexName: 'mobility_interests_department_fk')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained(indexName: 'mobility_interests_location_fk')->nullOnDelete();
            $table->foreignId('career_track_id')->nullable()->constrained(indexName: 'mobility_interests_track_fk')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('active');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'interest_type', 'status']);
        });

        Schema::create('talent_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('criteria')->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('talent_pool_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('talent_pool_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('active_key', 64)->nullable();
            $table->text('reason');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('end_reason')->nullable();
            $table->timestamps();

            $table->unique('active_key', 'talent_pool_memberships_active_unique');
            $table->index(['tenant_id', 'talent_pool_id', 'status'], 'talent_pool_memberships_pool_index');
            $table->index(['tenant_id', 'employee_id', 'status'], 'talent_pool_memberships_employee_index');
        });

        Schema::create('talent_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('career_track_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mobility', 24)->nullable();
            $table->boolean('critical_role_interest')->default(false);
            $table->text('development_priorities')->nullable();
            $table->string('latest_review_outcome', 32)->nullable();
            $table->text('confidential_notes')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('talent_assessment_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('talent_assessment_model_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('talent_assessment_model_id')->constrained(indexName: 'talent_model_versions_model_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('dimensions');
            $table->string('checksum', 64);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['talent_assessment_model_id', 'version'], 'talent_model_versions_unique');
        });

        Schema::create('talent_review_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('organisation_node_id')->nullable()->constrained(indexName: 'talent_reviews_node_fk')->nullOnDelete();
            $table->foreignId('facilitator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('participants')->nullable();
            $table->date('scheduled_for')->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('workflow_instance_id')->nullable()->constrained(indexName: 'talent_reviews_workflow_fk')->nullOnDelete();
            $table->text('decision_summary')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('critical_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('designation_id')->constrained()->restrictOnDelete();
            $table->foreignId('organisation_node_id')->nullable()->constrained(indexName: 'critical_positions_node_fk')->nullOnDelete();
            $table->string('scope_key', 32)->default('');
            $table->string('title');
            $table->string('status', 16)->default('active');
            $table->string('active_key', 96)->nullable();
            $table->unsignedSmallInteger('review_frequency_months')->default(12);
            $table->date('next_review_on')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('current_assessment_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('active_key', 'critical_positions_active_unique');
            $table->index(['tenant_id', 'status', 'next_review_on'], 'critical_positions_review_index');
        });

        Schema::create('critical_position_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('critical_position_id')->constrained(indexName: 'critical_assessments_position_fk')->restrictOnDelete();
            $table->string('criticality', 16);
            $table->string('business_impact', 16);
            $table->string('scarcity', 16);
            $table->string('replacement_difficulty', 16);
            $table->string('operational_dependency', 16);
            $table->text('reason');
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at');
            $table->date('effective_from');
            $table->timestamp('created_at')->nullable();

            $table->index(['critical_position_id', 'id'], 'critical_assessments_history');
        });

        Schema::create('succession_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('critical_position_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->string('active_key', 64)->nullable();
            $table->string('vacancy_risk', 16)->nullable();
            $table->date('review_date')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('confidential_notes')->nullable();
            $table->foreignId('workflow_instance_id')->nullable()->constrained(indexName: 'succession_plans_workflow_fk')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('closure_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('active_key', 'succession_plans_active_unique');
            $table->index(['tenant_id', 'status', 'review_date']);
        });

        Schema::create('successors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('succession_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('active');
            $table->string('active_key', 64)->nullable();
            $table->text('strengths')->nullable();
            $table->text('development_gaps')->nullable();
            $table->text('confidential_notes')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('added_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->text('removal_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique('active_key', 'successors_active_unique');
            $table->index(['tenant_id', 'employee_id', 'status']);
        });

        Schema::create('readiness_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('critical_position_id')->nullable()->constrained(indexName: 'readiness_position_fk')->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained(indexName: 'readiness_designation_fk')->nullOnDelete();
            $table->foreignId('successor_id')->nullable()->constrained(indexName: 'readiness_successor_fk')->nullOnDelete();
            $table->string('target_key', 64);
            $table->string('readiness_level', 24);
            $table->text('reason');
            $table->text('evidence')->nullable();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('current');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'target_key', 'status'], 'readiness_lookup');
            $table->index(['tenant_id', 'critical_position_id', 'status'], 'readiness_position_index');
        });

        Schema::create('talent_review_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('talent_review_session_id')->constrained(indexName: 'talent_review_items_session_fk')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('target_designation_id')->nullable()->constrained('designations', indexName: 'talent_review_items_designation_fk')->nullOnDelete();
            $table->foreignId('critical_position_id')->nullable()->constrained(indexName: 'talent_review_items_position_fk')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('decision', 32)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['talent_review_session_id', 'employee_id'], 'talent_review_items_unique');
        });

        Schema::create('talent_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('talent_assessment_model_version_id')->constrained(indexName: 'talent_assessments_model_version_fk')->restrictOnDelete();
            $table->foreignId('talent_review_item_id')->nullable()->constrained(indexName: 'talent_assessments_review_item_fk')->nullOnDelete();
            $table->json('ratings');
            $table->text('rationale')->nullable();
            $table->text('confidential_notes')->nullable();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at')->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('corrects_assessment_id')->nullable()->constrained('talent_assessments', indexName: 'talent_assessments_corrects_fk')->restrictOnDelete();
            $table->text('correction_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status'], 'talent_assessments_employee_index');
        });

        Schema::create('talent_development_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('successor_id')->nullable()->constrained(indexName: 'talent_actions_successor_fk')->nullOnDelete();
            $table->foreignId('talent_review_item_id')->nullable()->constrained(indexName: 'talent_actions_review_item_fk')->nullOnDelete();
            $table->foreignId('development_plan_item_id')->constrained(indexName: 'talent_actions_plan_item_fk')->restrictOnDelete();
            $table->string('action_type', 24);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('talent_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reminder', 32);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->date('reminded_on');
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'reminder', 'subject_type', 'subject_id', 'reminded_on'], 'talent_reminder_logs_unique');
        });
    }

    public function down(): void
    {
        foreach (['talent_reminder_logs', 'talent_development_actions', 'talent_assessments', 'talent_review_items', 'readiness_assessments', 'successors', 'succession_plans', 'critical_position_assessments', 'critical_positions', 'talent_review_sessions', 'talent_assessment_model_versions', 'talent_assessment_models', 'talent_profiles', 'talent_pool_memberships', 'talent_pools', 'mobility_interests', 'career_goals', 'career_aspiration_entries', 'career_profiles', 'role_requirement_versions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('career_paths', function (Blueprint $t) {
            foreach (['career_paths_track_fk', 'career_paths_business_unit_fk', 'career_paths_node_fk', 'career_paths_current_version_fk'] as $fk) {
                $t->dropForeign($fk);
            }
            $t->dropColumn(['career_track_id', 'business_unit_id', 'organisation_node_id', 'effective_from', 'effective_to', 'current_version_id']);
        });
        Schema::dropIfExists('career_path_versions');
        Schema::dropIfExists('career_tracks');
    }
};
