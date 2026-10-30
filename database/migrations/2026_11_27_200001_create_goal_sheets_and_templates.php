<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity HP-8d / HP-8e (Goal → Goal sheet policy, Goal template): goal templates and their KRAs / KPIs
 * (assigned by the system or picked when a goal is created), the goal sheets submitted for approval, the template a goal
 * came from, achievement percentages and KPI ratings in reviews, and evidence uploaded with a review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goal_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->string('description', 1000)->nullable();
            $table->json('applicability')->nullable()->comment('rule conditions: whose goal sheet the system maps it to; empty = everyone');
            $table->foreignId('feedback_template_id')->nullable()->constrained()->nullOnDelete()->comment('feedback form answered by the manager at the manager review');
            $table->string('status', 16)->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'goal_tpl_tenant_code_unique');
        });

        Schema::create('goal_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_item_id')->nullable()->constrained('goal_template_items')->cascadeOnDelete()->comment('set on a KPI: the KRA it belongs to');
            $table->foreignId('kra_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('description', 1000)->nullable();
            $table->string('measure_type', 16)->default('percentage');
            $table->foreignId('unit_id')->nullable()->constrained('performance_units')->nullOnDelete();
            $table->decimal('start_value', 14, 2)->default(0);
            $table->decimal('target_value', 14, 2)->nullable();
            $table->unsignedTinyInteger('weight')->default(0);
            $table->boolean('is_reverse')->default(false);
            $table->foreignId('perspective_id')->nullable()->constrained('performance_lookups')->nullOnDelete();
            $table->foreignId('kpi_rating_formula_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('goal_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_cycle_id')->constrained()->cascadeOnDelete();
            $table->string('approval', 16)->default('manager')->comment('manager|workflow (goal sheet policy plan_approval)');
            $table->string('status', 16)->default('pending')->comment('pending|approved|rejected');
            $table->unsignedSmallInteger('goals')->default(0);
            $table->decimal('total_weight', 8, 2)->default(0);
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'goal_plans_tenant_status_idx');
            $table->index(['employee_id', 'performance_cycle_id'], 'goal_plans_employee_cycle_idx');
        });

        Schema::table('goals', function (Blueprint $table) {
            $table->foreignId('goal_template_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('appraisal_ratings', function (Blueprint $table) {
            $table->decimal('achievement_pct', 8, 2)->nullable()->comment('achievement % entered when the review input is a percentage');
        });
        Schema::table('appraisal_reviews', function (Blueprint $table) {
            $table->json('evidence')->nullable()->comment('stored file paths uploaded with the review');
        });
    }

    public function down(): void
    {
        Schema::table('appraisal_reviews', fn (Blueprint $table) => $table->dropColumn('evidence'));
        Schema::table('appraisal_ratings', fn (Blueprint $table) => $table->dropColumn('achievement_pct'));
        Schema::table('goals', fn (Blueprint $table) => $table->dropConstrainedForeignId('goal_template_id'));
        Schema::dropIfExists('goal_plans');
        Schema::dropIfExists('goal_template_items');
        Schema::dropIfExists('goal_templates');
    }
};
