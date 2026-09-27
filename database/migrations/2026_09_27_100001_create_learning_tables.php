<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('code', 32);
            $table->string('type', 32)->default('elearning');
            $table->string('category', 32)->default('other');
            $table->text('description')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('content_url')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->unsignedSmallInteger('validity_months')->nullable();
            $table->unsignedTinyInteger('passing_score')->nullable();
            $table->unsignedTinyInteger('attempts_allowed')->default(3);
            $table->foreignId('owner_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 32)->default('draft');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('course_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('type', 32)->default('text');
            $table->text('content')->nullable();
            $table->string('url')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->json('questions');
            $table->unsignedTinyInteger('passing_score')->default(70);
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();
            $table->boolean('shuffle')->default(false);
            $table->timestamps();
        });

        Schema::create('learning_paths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('learning_path_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_path_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->unique(['learning_path_id', 'course_id']);
        });

        Schema::create('learning_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('learning_path_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('conditions')->nullable();
            $table->unsignedSmallInteger('due_days')->default(30);
            $table->unsignedSmallInteger('recur_months')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('auto_enrol_new_joiners')->default(true);
            $table->string('status', 32)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('learning_enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_path_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('learning_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('enrolled');
            $table->boolean('is_mandatory')->default(false);
            $table->json('completed_module_ids')->nullable();
            $table->decimal('progress', 5, 2)->default(0);
            $table->decimal('score', 5, 2)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->date('due_on')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->date('expires_on')->nullable();
            $table->foreignId('enrolled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status']);
            $table->index(['tenant_id', 'course_id', 'status']);
        });

        Schema::create('assessment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_enrolment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->json('answers');
            $table->decimal('score', 5, 2);
            $table->boolean('passed')->default(false);
            $table->timestamp('submitted_at');
            $table->timestamps();
        });

        Schema::create('training_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('mode', 16)->default('classroom');
            $table->foreignId('trainer_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('trainer_name')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('venue')->nullable();
            $table->string('meeting_url')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('status', 32)->default('scheduled');
            $table->timestamps();
        });

        Schema::create('training_session_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_enrolment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('registered');
            $table->text('feedback')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->timestamps();

            $table->unique(['training_session_id', 'employee_id'], 'session_attendee_unique');
        });

        Schema::create('learning_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_enrolment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 32);
            $table->date('issued_on');
            $table->date('expires_on')->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->string('status', 32)->default('valid');
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
        });
    }

    public function down(): void
    {
        foreach (['learning_certificates', 'training_session_attendees', 'training_sessions', 'assessment_attempts', 'learning_enrolments', 'learning_assignments', 'learning_path_courses', 'learning_paths', 'assessments', 'course_modules', 'courses'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
