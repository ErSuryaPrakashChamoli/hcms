<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key', 64);
            $table->text('description')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->json('conditions')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('onboarding_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('onboarding_template_id')->constrained()->cascadeOnDelete();
            $table->string('phase', 32);
            $table->string('type', 32)->default('task');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('owner_type', 32)->default('employee');
            $table->foreignId('owner_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('form_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('due_offset_days')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['onboarding_template_id', 'phase', 'sort_order'], 'onboarding_items_template_phase_idx');
        });

        Schema::create('onboarding_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('onboarding_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('in_progress');
            $table->date('anchor_date');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['employee_id']);
        });

        Schema::create('onboarding_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('onboarding_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('onboarding_template_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phase', 32);
            $table->string('type', 32)->default('task');
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('form_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('form_submission_id')->nullable()->constrained()->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->string('status', 32)->default('pending');
            $table->string('note')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'owner_user_id', 'status']);
            $table->index(['tenant_id', 'owner_role_id', 'status']);
            $table->index(['onboarding_plan_id', 'phase', 'sort_order'], 'onboarding_tasks_plan_phase_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_tasks');
        Schema::dropIfExists('onboarding_plans');
        Schema::dropIfExists('onboarding_template_items');
        Schema::dropIfExists('onboarding_templates');
    }
};
