<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity HD-B — Helpdesk resolver rules: named routing across categories and services (sub-categories),
 * optionally narrowed to companies and locations, with the resolving team (role), a default resolver, a role to
 * intimate and the SLA policy whose service days and hours apply. A request remembers the rule that routed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_resolver_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('priority')->default(100)->comment('lower runs first');
            $table->json('category_ids')->nullable()->comment('empty = any category');
            $table->json('service_ids')->nullable()->comment('empty = any service');
            $table->json('company_ids')->nullable()->comment('empty = any company');
            $table->json('location_ids')->nullable()->comment('empty = any location');
            $table->foreignId('assignee_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('notify_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('sla_policy_id')->nullable()->constrained('service_sla_policies')->nullOnDelete();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'priority'], 'srr_tenant_status_priority_idx');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('resolver_rule_id')->nullable()->after('sla_policy_id')->constrained('service_resolver_rules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolver_rule_id');
        });
        Schema::dropIfExists('service_resolver_rules');
    }
};
