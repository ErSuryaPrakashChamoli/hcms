<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity P13 (Forms & templates → Feedback templates): question banks and their questions, feedback
 * templates bound to a process (exit interviews by the employee, the manager and HR, confirmation, probation, induction),
 * and the feedback asked of someone about an employee, answered against a copy of the template's questions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->string('module', 16)->default('workforce');
            $table->string('description', 500)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'qb_tenant_code_unique');
        });

        Schema::create('question_bank_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_bank_id')->constrained()->cascadeOnDelete();
            $table->string('title', 500);
            $table->string('type', 16)->comment('peopleos.engagement.question_types');
            $table->json('options')->nullable()->comment('[{value, label}] for choice questions');
            $table->unsignedTinyInteger('scale_max')->nullable()->comment('rating questions: 1 to this');
            $table->boolean('is_mandatory')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        Schema::create('feedback_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->string('module', 16)->default('workforce');
            $table->string('process', 32)->comment('peopleos.feedback.processes');
            $table->string('description', 500)->nullable();
            $table->json('questions')->comment('[{key, title, type, options, scale_max, mandatory, bank_question_id}]');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'ft_tenant_code_unique');
            $table->index(['tenant_id', 'process', 'status'], 'ft_tenant_process_idx');
        });

        Schema::create('feedback_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feedback_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('process', 32);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete()->comment('the employee the feedback is about');
            $table->string('respondent', 16)->comment('employee|manager|hr|owner');
            $table->foreignId('respondent_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('respondent_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->boolean('share_with_next_level')->default(false)->comment('the manager\'s own manager may read it');
            $table->string('title', 160);
            $table->json('questions')->comment('copy of the template questions when asked');
            $table->json('answers')->nullable();
            $table->string('status', 16)->default('pending')->comment('pending|submitted|cancelled');
            $table->date('due_on')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'fr_tenant_status_idx');
            $table->index(['subject_type', 'subject_id'], 'fr_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_requests');
        Schema::dropIfExists('feedback_templates');
        Schema::dropIfExists('question_bank_questions');
        Schema::dropIfExists('question_banks');
    }
};
