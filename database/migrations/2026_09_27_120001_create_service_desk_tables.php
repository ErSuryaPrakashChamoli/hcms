<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sla_hours')->default(48);
            $table->unsignedSmallInteger('first_response_hours')->default(8);
            $table->foreignId('default_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('escalation_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->string('workflow_key', 64)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->foreignId('ticket_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->text('description');
            $table->string('priority', 16)->default('normal');
            $table->string('status', 32)->default('new');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('resolution')->nullable();
            $table->unsignedTinyInteger('satisfaction')->nullable();
            $table->text('satisfaction_comment')->nullable();
            $table->foreignId('article_id')->nullable();
            $table->foreignId('workflow_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'assignee_id']);
            $table->index(['tenant_id', 'employee_id']);
        });

        Schema::create('ticket_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->timestamps();
        });

        Schema::create('grievance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->text('description')->nullable();
            $table->boolean('is_confidential')->default(true);
            $table->boolean('allow_anonymous')->default(false);
            $table->json('handler_role_ids')->nullable();
            $table->unsignedSmallInteger('sla_days')->default(30);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('grievances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->foreignId('grievance_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_anonymous')->default(false);
            $table->string('subject');
            $table->text('details');
            $table->string('severity', 16)->default('medium');
            $table->string('status', 32)->default('submitted');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('access_user_ids')->nullable();
            $table->date('due_on')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('grievance_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grievance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 16)->default('note');
            $table->text('body');
            $table->boolean('visible_to_employee')->default(false);
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['grievance_notes', 'grievances', 'grievance_categories', 'ticket_comments', 'tickets', 'ticket_categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
