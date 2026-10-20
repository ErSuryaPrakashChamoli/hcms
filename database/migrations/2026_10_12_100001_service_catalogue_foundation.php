<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12: the HR service catalogue (effective-dated, versioned service definitions with the Phase 11
 * configuration lifecycle), SLA policies, and the locked number sequences that replace the unlocked
 * "last number + 1" read for request and grievance numbers. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('sequence', 32);
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'sequence']);
        });

        Schema::create('service_sla_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('calendar', 16)->default('business');
            $table->json('targets');
            $table->unsignedTinyInteger('warn_percent')->default(75);
            $table->foreignId('escalation_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->unsignedSmallInteger('escalation_repeat_hours')->default(24);
            $table->unsignedTinyInteger('max_escalation_level')->default(3);
            $table->json('pause_statuses')->nullable();
            $table->json('business_hours')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code', 'effective_from']);
        });

        Schema::create('service_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->foreignId('ticket_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('subcategory', 64)->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('service_definition_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_definition_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('draft');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('form_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('field_security')->nullable();
            $table->json('availability')->nullable();
            $table->json('lifecycle_states')->nullable();
            $table->json('org_scope')->nullable();
            $table->json('eligibility')->nullable();
            $table->string('attachment_rule', 16)->default('optional');
            $table->boolean('approval_required')->default(false);
            $table->string('workflow_key', 64)->nullable();
            $table->string('domain_action', 64)->nullable();
            $table->foreignId('sla_policy_id')->nullable()->constrained('service_sla_policies')->restrictOnDelete();
            $table->string('default_priority', 16)->default('normal');
            $table->json('assignment')->nullable();
            $table->string('confidentiality', 16)->default('standard');
            $table->boolean('visible_to_employee')->default(true);
            $table->boolean('manager_visible')->default(false);
            $table->boolean('listed')->default(true);
            $table->text('change_note')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['service_definition_id', 'version']);
            $table->index(['tenant_id', 'status', 'effective_from'], 'service_versions_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_definition_versions');
        Schema::dropIfExists('service_definitions');
        Schema::dropIfExists('service_sla_policies');
        Schema::dropIfExists('number_sequences');
    }
};
