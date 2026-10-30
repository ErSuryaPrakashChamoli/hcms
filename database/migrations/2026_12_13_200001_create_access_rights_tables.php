<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity CA-9 (Access rights) and SV-7 (rule-based role membership):
 *
 * - access_scope_groups / _users / _rules: module data-access groups. A group only ever narrows what its members see
 *   (an extra constraint inside AccessScopes, on top of the user's own organisation scope); no group = as before.
 * - role_membership_rules: who holds a role, by an organisation rule. The grants a rule makes are role_user rows tagged
 *   source = rule (with the rule); manual grants (source = manual, the default for every existing row) are never touched.
 * - roles.myspace_hidden_items: page access per My space item for the role's holders (null = nothing hidden, as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_scope_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('module', 32);
            $table->string('basis', 16)->default('organisation');
            $table->string('reportees', 8)->default('none');
            $table->boolean('include_sub_departments')->default(false);
            $table->string('status', 16)->default('active');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'access_scope_groups_tenant_name_unique');
            $table->index(['tenant_id', 'module', 'status'], 'access_scope_groups_tenant_module_idx');
        });

        Schema::create('access_scope_group_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_scope_group_id')->constrained('access_scope_groups', indexName: 'asg_users_group_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', indexName: 'asg_users_user_fk')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['access_scope_group_id', 'user_id'], 'asg_users_group_user_unique');
            $table->index('user_id', 'asg_users_user_idx');
        });

        Schema::create('access_scope_group_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_scope_group_id')->constrained('access_scope_groups', indexName: 'asg_rules_group_fk')->cascadeOnDelete();
            $table->string('dimension', 32);
            $table->unsignedBigInteger('scope_id');
            $table->timestamps();

            $table->unique(['access_scope_group_id', 'dimension', 'scope_id'], 'asg_rules_group_dimension_scope_unique');
        });

        Schema::create('role_membership_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles', indexName: 'role_membership_rules_role_fk')->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('conditions');
            $table->string('match', 8)->default('all');
            $table->string('status', 16)->default('active');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'role_membership_rules_created_by_fk')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', indexName: 'role_membership_rules_updated_by_fk')->nullOnDelete();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'role_membership_rules_tenant_status_idx');
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->string('source', 16)->default('manual')->after('company_id');
            $table->foreignId('membership_rule_id')->nullable()->after('source')
                ->constrained('role_membership_rules', indexName: 'role_user_membership_rule_fk')->nullOnDelete();
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->json('myspace_hidden_items')->nullable()->after('reliever_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('myspace_hidden_items'));
        Schema::table('role_user', function (Blueprint $table) {
            $table->dropForeign('role_user_membership_rule_fk');
            $table->dropColumn(['source', 'membership_rule_id']);
        });
        Schema::dropIfExists('role_membership_rules');
        Schema::dropIfExists('access_scope_group_rules');
        Schema::dropIfExists('access_scope_group_users');
        Schema::dropIfExists('access_scope_groups');
    }
};
