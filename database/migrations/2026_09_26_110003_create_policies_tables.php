<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Policy Engine (§43): a policy is a named, typed bundle of settings with versions;
        // assignment rules decide which policy applies to which employees.
        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('name');
            $table->string('code', 64);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'type', 'status']);
        });

        Schema::create('policy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('settings');
            $table->string('status', 32)->default('draft');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->string('change_note')->nullable();
            $table->timestamps();

            $table->unique(['policy_id', 'version']);
            $table->index(['tenant_id', 'policy_id', 'status']);
        });

        Schema::create('policy_assignment_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('policy_type', 32);
            $table->foreignId('policy_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('priority')->default(100);
            $table->string('match', 8)->default('all');
            $table->json('conditions');
            $table->string('status', 32)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'policy_type', 'status', 'priority'], 'policy_rules_type_status_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_assignment_rules');
        Schema::dropIfExists('policy_versions');
        Schema::dropIfExists('policies');
    }
};
