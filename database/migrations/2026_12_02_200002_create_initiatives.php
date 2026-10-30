<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity HP-8j — Initiatives: something an employee takes on (an initiative type from the performance masters:
 * buddy, induction, initiative, PIP, training need …), optionally linked to one of their goals, with dates, progress and
 * status, and the feedback asked of colleagues or given by the manager.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('initiatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->comment('the employee the initiative is assigned to')->constrained()->cascadeOnDelete();
            $table->foreignId('initiative_type_id')->nullable()->comment('performance_lookups kind initiative_type')
                ->constrained('performance_lookups', 'id', 'initiatives_type_fk')->nullOnDelete();
            $table->foreignId('goal_id')->nullable()->constrained('goals', 'id', 'initiatives_goal_fk')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->comment('the employee who assigned it (null = the employee themself)')
                ->constrained('employees', 'id', 'initiatives_owner_fk')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 16)->default('planned')->comment('planned|in_progress|completed|cancelled');
            $table->unsignedTinyInteger('progress')->default(0)->comment('0-100');
            $table->text('outcome')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'initiatives_created_by_fk')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status'], 'initiatives_employee_status_index');
        });

        Schema::create('initiative_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiative_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->comment('the employee who gives (or was asked for) the feedback')
                ->constrained('employees', 'id', 'initiative_feedback_reviewer_fk')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->comment('null = given without a request (the manager)')
                ->constrained('users', 'id', 'initiative_feedback_requested_by_fk')->nullOnDelete();
            $table->string('status', 16)->default('requested')->comment('requested|given|ignored');
            $table->text('feedback')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'reviewer_id', 'status'], 'initiative_feedback_reviewer_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('initiative_feedback');
        Schema::dropIfExists('initiatives');
    }
};
