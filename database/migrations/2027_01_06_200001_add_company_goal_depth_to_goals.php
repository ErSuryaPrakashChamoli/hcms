<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity GO-4 (matrix 27 §27.2.1 #12, §27.2.3 #3–9 and 13–15, §27.7 #4, 8, 10, §27.10 #2): company goal and OKR depth.
 *
 * - goals: the owner person of an organisation goal; the health reported at check-in (null = not started); visibility
 *   (public = today; private only for organisation goals, owner decision PER-5 / D-GO5); tags; the actual achievement that
 *   overrides the system-calculated one (owner decision PER-6 / D-GO7, only while `performance.goals.achievement_override`
 *   is on) with its reason, who and when — the system value stays in `progress`.
 * - goal_check_ins: the health chosen at check-in and the period it covers.
 * - goal_viewers: the "visible to" list of a private goal.
 * - goal_alignments: every period a goal was aligned (linked) to a parent goal — "linked on" / "unlinked on"; the open rows
 *   are backfilled from today's `parent_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->foreignId('owner_employee_id')->nullable()->after('organisation_node_id')
                ->constrained('employees', 'id', 'goals_owner_employee_fk')->nullOnDelete();
            $table->string('health', 16)->nullable()->comment('null = not started; not_started, on_track, behind, at_risk');
            $table->string('visibility', 16)->default('public');
            $table->json('tags')->nullable();
            $table->decimal('achievement_override', 7, 2)->nullable()->comment('actual achievement (%) overriding progress; read only while the setting is on');
            $table->string('achievement_override_reason', 500)->nullable();
            $table->foreignId('achievement_overridden_by')->nullable()->constrained('users', 'id', 'goals_achievement_overridden_by_fk')->nullOnDelete();
            $table->timestamp('achievement_overridden_at')->nullable();

            $table->index(['tenant_id', 'owner_employee_id'], 'goals_tenant_owner_idx');
            $table->index(['tenant_id', 'visibility'], 'goals_tenant_visibility_idx');
        });

        Schema::table('goal_check_ins', function (Blueprint $table) {
            $table->string('health', 16)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
        });

        Schema::create('goal_viewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants', 'id', 'goal_viewers_tenant_fk')->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained('goals', 'id', 'goal_viewers_goal_fk')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees', 'id', 'goal_viewers_employee_fk')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'goal_viewers_created_by_fk')->nullOnDelete();
            $table->timestamps();

            $table->unique(['goal_id', 'employee_id'], 'goal_viewers_goal_employee_unique');
            $table->index(['tenant_id', 'employee_id'], 'goal_viewers_tenant_employee_idx');
        });

        Schema::create('goal_alignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants', 'id', 'goal_alignments_tenant_fk')->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained('goals', 'id', 'goal_alignments_goal_fk')->cascadeOnDelete();
            $table->foreignId('parent_id')->constrained('goals', 'id', 'goal_alignments_parent_fk')->cascadeOnDelete();
            $table->timestamp('linked_at');
            $table->timestamp('unlinked_at')->nullable();
            $table->foreignId('linked_by')->nullable()->constrained('users', 'id', 'goal_alignments_linked_by_fk')->nullOnDelete();
            $table->foreignId('unlinked_by')->nullable()->constrained('users', 'id', 'goal_alignments_unlinked_by_fk')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'parent_id'], 'goal_alignments_tenant_parent_idx');
            $table->index(['goal_id', 'unlinked_at'], 'goal_alignments_goal_open_idx');
        });

        // Today's alignments become open "linked" rows (linked on = when the goal was created).
        $now = now();
        DB::table('goals')->whereNotNull('parent_id')->orderBy('id')->select(['id', 'tenant_id', 'parent_id', 'created_at', 'created_by'])
            ->chunk(500, function ($goals) use ($now) {
                DB::table('goal_alignments')->insert($goals->map(fn ($g) => [
                    'tenant_id' => $g->tenant_id, 'goal_id' => $g->id, 'parent_id' => $g->parent_id, 'linked_at' => $g->created_at ?? $now,
                    'linked_by' => $g->created_by, 'created_at' => $now, 'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_alignments');
        Schema::dropIfExists('goal_viewers');

        Schema::table('goal_check_ins', function (Blueprint $table) {
            $table->dropColumn(['health', 'period_start', 'period_end']);
        });

        Schema::table('goals', function (Blueprint $table) {
            $table->dropForeign('goals_owner_employee_fk');
            $table->dropForeign('goals_achievement_overridden_by_fk');
            $table->dropIndex('goals_tenant_owner_idx');
            $table->dropIndex('goals_tenant_visibility_idx');
            $table->dropColumn(['owner_employee_id', 'health', 'visibility', 'tags', 'achievement_override', 'achievement_override_reason', 'achievement_overridden_by', 'achievement_overridden_at']);
        });
    }
};
