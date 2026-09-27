<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key', 64);
            $table->text('description')->nullable();
            $table->string('trigger_event', 64)->default('manual');
            $table->string('subject_type')->nullable();
            $table->json('start_conditions')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
            $table->index(['tenant_id', 'trigger_event', 'status']);
        });

        // definition = { nodes: [{id, type, name, config}], edges: [{from, to, label}] }; immutable once published.
        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('definition');
            $table->string('status', 32)->default('draft');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['workflow_id', 'version']);
        });

        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_version_id')->constrained('workflow_versions')->restrictOnDelete();
            $table->nullableMorphs('subject');
            $table->string('subject_label')->nullable();
            $table->string('status', 32)->default('running');
            $table->string('current_node_id', 64)->nullable();
            $table->json('context')->nullable();
            $table->string('outcome', 32)->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('wake_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'wake_at']);
        });

        Schema::create('workflow_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_instance_id')->constrained()->cascadeOnDelete();
            $table->string('node_id', 64);
            $table->string('type', 32);
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('status', 32)->default('pending');
            $table->string('decision', 32)->nullable();
            $table->string('note')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('last_reminded_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'assignee_id', 'status']);
            $table->index(['tenant_id', 'assignee_role_id', 'status']);
            $table->index(['tenant_id', 'status', 'due_at']);
            $table->index(['workflow_instance_id', 'node_id']);
        });

        Schema::create('workflow_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_instance_id')->constrained()->cascadeOnDelete();
            $table->string('node_id', 64)->nullable();
            $table->string('action', 64);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('created_at');

            $table->index(['workflow_instance_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_actions');
        Schema::dropIfExists('workflow_tasks');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflows');
    }
};
