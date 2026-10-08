<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 8 — Learning, Development & Skills Growth foundation. Additive only.
 |
 | providers / instructors          : who delivers learning (instructors may be employees)
 | course_versions                  : immutable curriculum snapshots pinned by enrolments,
 |                                    completions and certificates
 | learning_path_versions           : immutable path snapshots (order, prerequisites, milestones)
 | learning_programs (+ versions, participants)
 | learning_completions             : append-only completion records with corrections
 | learning_evidence / learning_costs
 | skill_scales (+ versions), employee_skills (sourced history), skill_assessments
 | development_plans (+ items)      : consume Phase 7 development_needs
 | learning_reminder_logs           : one reminder per subject per day
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('provider_type', 16)->default('external');
            $table->text('description')->nullable();
            $table->string('website')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->text('address')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('learning_instructors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('learning_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->text('bio')->nullable();
            $table->string('specialisation')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id']);
        });

        Schema::create('course_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('delivery_mode', 32)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->foreignId('learning_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('learning_instructor_id')->nullable()->constrained()->nullOnDelete();
            $table->json('curriculum');
            $table->json('assessment')->nullable();
            $table->unsignedTinyInteger('passing_score')->nullable();
            $table->unsignedTinyInteger('attempts_allowed')->default(3);
            $table->unsignedSmallInteger('validity_months')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('checksum', 64);
            $table->string('status', 16)->default('published');
            $table->date('effective_from')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'version'], 'course_versions_unique');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->string('topic', 64)->nullable()->after('category');
            $table->string('difficulty', 16)->nullable()->after('topic');
            $table->string('delivery_mode', 32)->nullable()->after('difficulty');
            $table->string('language', 16)->nullable()->after('delivery_mode');
            $table->foreignId('learning_provider_id')->nullable()->after('owner_id')->constrained(indexName: 'courses_provider_fk')->nullOnDelete();
            $table->foreignId('learning_instructor_id')->nullable()->after('learning_provider_id')->constrained(indexName: 'courses_instructor_fk')->nullOnDelete();
            $table->decimal('cost', 12, 2)->nullable()->after('learning_instructor_id');
            $table->string('currency', 3)->nullable()->after('cost');
            $table->json('prerequisite_course_ids')->nullable()->after('currency');
            $table->boolean('allow_self_enrol')->default(false)->after('prerequisite_course_ids');
            $table->boolean('requires_approval')->default(false)->after('allow_self_enrol');
            $table->string('approval_workflow_key', 64)->nullable()->after('requires_approval');
            $table->date('effective_from')->nullable()->after('status');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->foreignId('current_version_id')->nullable()->after('effective_to')->constrained('course_versions', indexName: 'courses_current_version_fk')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->after('current_version_id')->constrained('users', indexName: 'courses_submitted_by_fk')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->foreignId('approved_by')->nullable()->after('submitted_at')->constrained('users', indexName: 'courses_approved_by_fk')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->unsignedInteger('lock_version')->default(0)->after('approved_at');
        });

        Schema::create('learning_path_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_path_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('name');
            $table->json('items');
            $table->json('milestones')->nullable();
            $table->string('checksum', 64);
            $table->string('status', 16)->default('published');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['learning_path_id', 'version'], 'learning_path_versions_unique');
        });

        Schema::table('learning_paths', function (Blueprint $table) {
            $table->json('milestones')->nullable()->after('description');
            $table->foreignId('current_version_id')->nullable()->after('status')->constrained('learning_path_versions', indexName: 'learning_paths_current_version_fk')->nullOnDelete();
        });

        Schema::table('learning_path_courses', function (Blueprint $table) {
            $table->json('prerequisite_course_ids')->nullable()->after('is_required');
        });

        Schema::create('learning_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('draft');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('learning_program_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_program_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->json('eligibility')->nullable();
            $table->json('items');
            $table->json('completion_rule');
            $table->boolean('issues_certificate')->default(false);
            $table->unsignedSmallInteger('validity_months')->nullable();
            $table->string('checksum', 64);
            $table->string('status', 16)->default('published');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['learning_program_id', 'version'], 'learning_program_versions_unique');
        });

        Schema::create('learning_program_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_program_version_id')->constrained(indexName: 'program_participants_version_fk')->restrictOnDelete();
            $table->string('status', 16)->default('enrolled');
            $table->foreignId('enrolled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['learning_program_version_id', 'employee_id'], 'program_participants_unique');
        });

        Schema::table('learning_assignments', function (Blueprint $table) {
            $table->string('target_type', 24)->default('population')->after('employee_id');
            $table->unsignedBigInteger('target_id')->nullable()->after('target_type');
            $table->string('priority', 16)->default('normal')->after('target_id');
            $table->text('reason')->nullable()->after('priority');
            $table->boolean('is_required')->default(true)->after('is_mandatory');
            $table->string('operation_id', 36)->nullable()->after('status');
            $table->unsignedInteger('version')->default(1)->after('operation_id');
            $table->date('effective_from')->nullable()->after('version');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->timestamp('assigned_at')->nullable()->after('effective_to');
            $table->timestamp('cancelled_at')->nullable()->after('assigned_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users', indexName: 'learning_assignments_cancelled_by_fk')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
        });

        Schema::table('learning_enrolments', function (Blueprint $table) {
            $table->foreignId('course_version_id')->nullable()->after('course_id')->constrained(indexName: 'learning_enrolments_course_version_fk')->restrictOnDelete();
            $table->foreignId('learning_path_version_id')->nullable()->after('learning_path_id')->constrained(indexName: 'learning_enrolments_path_version_fk')->restrictOnDelete();
            $table->foreignId('learning_program_participant_id')->nullable()->after('learning_path_version_id')->constrained(indexName: 'learning_enrolments_participant_fk')->nullOnDelete();
            $table->foreignId('training_session_id')->nullable()->after('learning_program_participant_id')->constrained(indexName: 'learning_enrolments_session_fk')->nullOnDelete();
            $table->unsignedInteger('assignment_version')->nullable()->after('learning_assignment_id');
            $table->string('priority', 16)->nullable()->after('is_mandatory');
            $table->text('reason')->nullable()->after('priority');
            $table->foreignId('requested_by')->nullable()->after('enrolled_by')->constrained('users', indexName: 'learning_enrolments_requested_by_fk')->nullOnDelete();
            $table->timestamp('requested_at')->nullable()->after('requested_by');
            $table->foreignId('approved_by')->nullable()->after('requested_at')->constrained('users', indexName: 'learning_enrolments_approved_by_fk')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('decision_note')->nullable()->after('approved_at');
            $table->foreignId('workflow_instance_id')->nullable()->after('decision_note')->constrained(indexName: 'learning_enrolments_workflow_fk')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('workflow_instance_id');
            $table->unsignedInteger('lock_version')->default(0)->after('cancelled_at');

            $table->index(['tenant_id', 'status', 'due_on'], 'learning_enrolments_due_index');
        });

        Schema::table('training_sessions', function (Blueprint $table) {
            $table->foreignId('course_version_id')->nullable()->after('course_id')->constrained(indexName: 'training_sessions_course_version_fk')->nullOnDelete();
            $table->foreignId('learning_instructor_id')->nullable()->after('trainer_name')->constrained(indexName: 'training_sessions_instructor_fk')->nullOnDelete();
        });

        Schema::table('training_session_attendees', function (Blueprint $table) {
            $table->unsignedInteger('waitlist_position')->nullable()->after('status');
            $table->timestamp('registered_at')->nullable()->after('waitlist_position');
            $table->timestamp('cancelled_at')->nullable()->after('registered_at');
        });

        Schema::create('learning_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('learning_enrolment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('learning_program_participant_id')->nullable()->constrained(indexName: 'learning_completions_participant_fk')->restrictOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->foreignId('course_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('course_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('learning_program_version_id')->nullable()->constrained(indexName: 'learning_completions_program_version_fk')->restrictOnDelete();
            $table->timestamp('completed_at');
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('score', 5, 2)->nullable();
            $table->string('grade', 16)->nullable();
            $table->string('attendance', 16)->nullable();
            $table->decimal('hours', 6, 2)->nullable();
            $table->text('evidence')->nullable();
            $table->foreignId('learning_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('learning_instructor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('final');
            $table->foreignId('corrects_completion_id')->nullable()->constrained('learning_completions', indexName: 'learning_completions_corrects_fk')->restrictOnDelete();
            $table->text('correction_reason')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['learning_enrolment_id', 'sequence'], 'learning_completions_enrolment_unique');
            $table->unique(['learning_program_participant_id', 'sequence'], 'learning_completions_participant_unique');
            $table->index(['tenant_id', 'employee_id', 'status'], 'learning_completions_employee_index');
        });

        Schema::table('learning_certificates', function (Blueprint $table) {
            $table->foreignId('course_version_id')->nullable()->after('course_id')->constrained(indexName: 'learning_certificates_course_version_fk')->restrictOnDelete();
            $table->foreignId('learning_program_version_id')->nullable()->after('course_version_id')->constrained(indexName: 'learning_certificates_program_version_fk')->restrictOnDelete();
            $table->foreignId('learning_completion_id')->nullable()->after('learning_enrolment_id')->constrained(indexName: 'learning_certificates_completion_fk')->restrictOnDelete();
            $table->string('issuer')->nullable()->after('score');
            $table->string('credential_url')->nullable()->after('issuer');
            $table->string('verification_code', 40)->nullable()->after('credential_url');
            $table->string('verification_status', 16)->default('verified')->after('verification_code');
            $table->string('document_path')->nullable()->after('verification_status');
            $table->string('document_name')->nullable()->after('document_path');
            $table->string('document_sha256', 64)->nullable()->after('document_name');
            $table->boolean('is_external')->default(false)->after('document_sha256');
            $table->date('recertification_due_on')->nullable()->after('expires_on');
            $table->foreignId('renewed_by_certificate_id')->nullable()->after('recertification_due_on')->constrained('learning_certificates', indexName: 'learning_certificates_renewed_by_fk')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable()->after('status');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users', indexName: 'learning_certificates_revoked_by_fk')->nullOnDelete();
            $table->text('revocation_reason')->nullable()->after('revoked_by');

            $table->unique('verification_code', 'learning_certificates_verification_unique');
            $table->index(['tenant_id', 'status', 'expires_on'], 'learning_certificates_expiry_index');
        });

        Schema::create('learning_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_enrolment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('sha256', 64);
            $table->string('status', 16)->default('submitted');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
        });

        Schema::create('learning_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_enrolment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('training_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('learning_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cost_type', 16);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->date('incurred_on');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'incurred_on']);
        });

        Schema::create('skill_scales', function (Blueprint $table) {
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

        Schema::create('skill_scale_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_scale_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('levels');
            $table->string('checksum', 64);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['skill_scale_id', 'version'], 'skill_scale_versions_unique');
        });

        Schema::table('skills', function (Blueprint $table) {
            $table->string('skill_type', 32)->nullable()->after('category');
            $table->text('description')->nullable()->after('skill_type');
            $table->foreignId('skill_scale_id')->nullable()->after('description')->constrained(indexName: 'skills_scale_fk')->nullOnDelete();
            $table->date('effective_from')->nullable()->after('status');
            $table->date('effective_to')->nullable()->after('effective_from');
        });

        Schema::create('skill_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->foreignId('skill_scale_version_id')->constrained()->restrictOnDelete();
            $table->string('assessment_type', 24);
            $table->foreignId('assessor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assessor_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('assessed_on');
            $table->decimal('level', 5, 2);
            $table->decimal('target_level', 5, 2)->nullable();
            $table->text('evidence')->nullable();
            $table->text('comments')->nullable();
            $table->text('private_notes')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('corrects_assessment_id')->nullable()->constrained('skill_assessments', indexName: 'skill_assessments_corrects_fk')->restrictOnDelete();
            $table->text('correction_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'skill_id', 'status'], 'skill_assessments_employee_index');
        });

        Schema::create('employee_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->foreignId('skill_scale_version_id')->constrained()->restrictOnDelete();
            $table->decimal('current_level', 5, 2)->nullable();
            $table->decimal('target_level', 5, 2)->nullable();
            $table->string('source', 24);
            $table->boolean('is_verified')->default(false);
            $table->text('evidence')->nullable();
            $table->foreignId('skill_assessment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('learning_completion_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('assessed_on')->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('confidence', 16)->nullable();
            $table->string('status', 16)->default('current');
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'skill_id', 'status'], 'employee_skills_lookup_index');
            $table->index(['tenant_id', 'skill_id', 'status'], 'employee_skills_skill_index');
        });

        Schema::table('development_needs', function (Blueprint $table) {
            $table->foreignId('skill_id')->nullable()->after('competency_id')->constrained(indexName: 'development_needs_skill_fk')->nullOnDelete();
            $table->foreignId('skill_scale_version_id')->nullable()->after('skill_id')->constrained(indexName: 'development_needs_scale_version_fk')->nullOnDelete();
            $table->decimal('current_level', 5, 2)->nullable()->after('skill_scale_version_id');
            $table->decimal('target_level', 5, 2)->nullable()->after('current_level');
            $table->string('current_state')->nullable()->after('target_level');
            $table->string('desired_state')->nullable()->after('current_state');
            $table->text('reason')->nullable()->after('description');
            $table->foreignId('owner_employee_id')->nullable()->after('reason')->constrained('employees', indexName: 'development_needs_owner_fk')->nullOnDelete();
            $table->date('target_date')->nullable()->after('owner_employee_id');
        });

        Schema::create('development_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('starts_on')->nullable();
            $table->date('target_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('private_notes')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status']);
        });

        Schema::create('development_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('development_plan_id')->constrained()->restrictOnDelete();
            $table->string('item_type', 16);
            $table->string('title');
            $table->foreignId('development_need_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('skill_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('current_level', 5, 2)->nullable();
            $table->decimal('target_level', 5, 2)->nullable();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('learning_path_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('learning_enrolment_id')->nullable()->constrained()->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['development_plan_id', 'sort_order']);
        });

        Schema::create('learning_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reminder', 32);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->date('reminded_on');
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'reminder', 'subject_type', 'subject_id', 'reminded_on'], 'learning_reminder_logs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_reminder_logs');
        Schema::dropIfExists('development_plan_items');
        Schema::dropIfExists('development_plans');
        Schema::table('development_needs', function (Blueprint $t) {
            $t->dropForeign('development_needs_skill_fk');
            $t->dropForeign('development_needs_scale_version_fk');
            $t->dropForeign('development_needs_owner_fk');
            $t->dropColumn(['skill_id', 'skill_scale_version_id', 'current_level', 'target_level', 'current_state', 'desired_state', 'reason', 'owner_employee_id', 'target_date']);
        });
        Schema::dropIfExists('employee_skills');
        Schema::dropIfExists('skill_assessments');
        Schema::table('skills', function (Blueprint $t) {
            $t->dropForeign('skills_scale_fk');
            $t->dropColumn(['skill_type', 'description', 'skill_scale_id', 'effective_from', 'effective_to']);
        });
        Schema::dropIfExists('skill_scale_versions');
        Schema::dropIfExists('skill_scales');
        Schema::dropIfExists('learning_costs');
        Schema::dropIfExists('learning_evidence');
        Schema::table('learning_certificates', function (Blueprint $t) {
            $t->dropForeign('learning_certificates_course_version_fk');
            $t->dropForeign('learning_certificates_program_version_fk');
            $t->dropForeign('learning_certificates_completion_fk');
            $t->dropForeign('learning_certificates_renewed_by_fk');
            $t->dropForeign('learning_certificates_revoked_by_fk');
            $t->dropUnique('learning_certificates_verification_unique');
            $t->dropIndex('learning_certificates_expiry_index');
            $t->dropColumn(['course_version_id', 'learning_program_version_id', 'learning_completion_id', 'issuer', 'credential_url', 'verification_code', 'verification_status', 'document_path', 'document_name', 'document_sha256', 'is_external', 'recertification_due_on', 'renewed_by_certificate_id', 'revoked_at', 'revoked_by', 'revocation_reason']);
        });
        Schema::dropIfExists('learning_completions');
        Schema::table('training_session_attendees', fn (Blueprint $t) => $t->dropColumn(['waitlist_position', 'registered_at', 'cancelled_at']));
        Schema::table('training_sessions', function (Blueprint $t) {
            $t->dropForeign('training_sessions_course_version_fk');
            $t->dropForeign('training_sessions_instructor_fk');
            $t->dropColumn(['course_version_id', 'learning_instructor_id']);
        });
        Schema::table('learning_enrolments', function (Blueprint $t) {
            foreach (['learning_enrolments_course_version_fk', 'learning_enrolments_path_version_fk', 'learning_enrolments_participant_fk', 'learning_enrolments_session_fk', 'learning_enrolments_requested_by_fk', 'learning_enrolments_approved_by_fk', 'learning_enrolments_workflow_fk'] as $fk) {
                $t->dropForeign($fk);
            }
            $t->dropIndex('learning_enrolments_due_index');
            $t->dropColumn(['course_version_id', 'learning_path_version_id', 'learning_program_participant_id', 'training_session_id', 'assignment_version', 'priority', 'reason', 'requested_by', 'requested_at', 'approved_by', 'approved_at', 'decision_note', 'workflow_instance_id', 'cancelled_at', 'lock_version']);
        });
        Schema::table('learning_assignments', function (Blueprint $t) {
            $t->dropForeign('learning_assignments_cancelled_by_fk');
            $t->dropColumn(['target_type', 'target_id', 'priority', 'reason', 'is_required', 'operation_id', 'version', 'effective_from', 'effective_to', 'assigned_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason']);
        });
        Schema::dropIfExists('learning_program_participants');
        Schema::dropIfExists('learning_program_versions');
        Schema::dropIfExists('learning_programs');
        Schema::table('learning_path_courses', fn (Blueprint $t) => $t->dropColumn('prerequisite_course_ids'));
        Schema::table('learning_paths', function (Blueprint $t) {
            $t->dropForeign('learning_paths_current_version_fk');
            $t->dropColumn(['milestones', 'current_version_id']);
        });
        Schema::dropIfExists('learning_path_versions');
        Schema::table('courses', function (Blueprint $t) {
            foreach (['courses_provider_fk', 'courses_instructor_fk', 'courses_current_version_fk', 'courses_submitted_by_fk', 'courses_approved_by_fk'] as $fk) {
                $t->dropForeign($fk);
            }
            $t->dropColumn(['topic', 'difficulty', 'delivery_mode', 'language', 'learning_provider_id', 'learning_instructor_id', 'cost', 'currency', 'prerequisite_course_ids', 'allow_self_enrol', 'requires_approval', 'approval_workflow_key', 'effective_from', 'effective_to', 'current_version_id', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'lock_version']);
        });
        Schema::dropIfExists('course_versions');
        Schema::dropIfExists('learning_instructors');
        Schema::dropIfExists('learning_providers');
    }
};
