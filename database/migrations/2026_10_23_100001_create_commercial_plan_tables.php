<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS.4 commercial plans (shadow mode). Additive only. No price, subscription, trial, invoice, payment or billing
 * table, and no row is written: no plan exists and no tenant is assigned one until a platform operator does it.
 *
 * - plans: the platform catalogue's stable identity (a code that never changes, a name, a description). No tenant.
 * - plan_versions: the commercial terms of a plan, draft → published → retired (the ADR-0009 vocabulary). A
 *   published version is immutable; its sale window (effective_from / effective_to) says when tenants can be
 *   assigned to it. At most one draft per plan (a generated column under a unique index).
 * - plan_entitlements: what a version says about each capability of the code-owned catalogue (one row per
 *   capability; an absent capability is "not in the plan").
 * - tenant_plan_assignments: which published version a tenant is on, effective-dated, history kept; at most one
 *   active assignment per start date (generated column + unique index, like the SaaS.3 configuration rows).
 * - tenant_entitlement_profiles.has_plan_assignments: lets the state loader skip the plan read for tenants that
 *   never had a plan (the SaaS.3 query counts stay as they were).
 * - entitlement_shadow_observations.last_assignment_id: the plan assignment behind the last observed decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained();
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('draft'); // draft | published | retired
            $table->date('effective_from')->nullable(); // sale window, set when published; inclusive
            $table->date('effective_to')->nullable();   // last day of sale; null = open-ended
            $table->string('change_note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->unsignedBigInteger('draft_plan_id')->nullable()->virtualAs("CASE WHEN status = 'draft' THEN plan_id END");
            $table->timestamps();

            $table->unique(['plan_id', 'version'], 'plan_versions_number_unique');
            $table->unique('draft_plan_id', 'plan_versions_one_draft_unique');
            $table->index(['plan_id', 'status'], 'plan_versions_status_index');
        });

        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_version_id')->constrained()->cascadeOnDelete();
            $table->string('capability', 64);
            $table->boolean('value_bool')->nullable();
            $table->unsignedBigInteger('value_int')->nullable(); // limits: null = unlimited
            $table->timestamps();

            $table->unique(['plan_version_id', 'capability'], 'plan_entitlements_capability_unique');
        });

        Schema::create('tenant_plan_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_version_id')->constrained();
            $table->date('effective_from');
            $table->date('effective_to')->nullable(); // inclusive; null = open-ended
            $table->string('status', 16)->default('active'); // active | cancelled (never took effect)
            $table->string('reason', 500);
            $table->string('reference', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 500)->nullable();
            $table->unsignedBigInteger('superseded_by')->nullable();
            $table->date('active_from')->nullable()->virtualAs("CASE WHEN status = 'active' THEN effective_from END");
            $table->timestamps();

            $table->unique(['tenant_id', 'active_from'], 'ten_plan_active_unique');
            $table->index(['tenant_id', 'effective_from'], 'ten_plan_lookup_index');
            $table->index(['plan_version_id', 'status'], 'ten_plan_version_index');
        });

        Schema::table('tenant_entitlement_profiles', function (Blueprint $table) {
            $table->boolean('has_plan_assignments')->default(false)->after('version');
        });

        Schema::table('entitlement_shadow_observations', function (Blueprint $table) {
            $table->unsignedBigInteger('last_assignment_id')->nullable()->after('last_override_id');
        });
    }

    public function down(): void
    {
        Schema::table('entitlement_shadow_observations', function (Blueprint $table) {
            $table->dropColumn('last_assignment_id');
        });
        Schema::table('tenant_entitlement_profiles', function (Blueprint $table) {
            $table->dropColumn('has_plan_assignments');
        });
        Schema::dropIfExists('tenant_plan_assignments');
        Schema::dropIfExists('plan_entitlements');
        Schema::dropIfExists('plan_versions');
        Schema::dropIfExists('plans');
    }
};
