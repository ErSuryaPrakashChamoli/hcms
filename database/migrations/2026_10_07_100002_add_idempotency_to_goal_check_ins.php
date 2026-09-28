<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Phase 7: API goal-progress writes honour an Idempotency-Key (one progress entry per goal and key). Additive. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goal_check_ins', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('source');
            $table->unique(['goal_id', 'idempotency_key'], 'goal_check_ins_idempotency_unique');
        });
        // The unique index serves the goal_id foreign key. MySQL drops its implicit FK index by
        // itself; an explicit one (left by a previous rollback) is dropped here so both paths match.
        if (Schema::hasIndex('goal_check_ins', 'goal_check_ins_goal_id_foreign')) {
            Schema::table('goal_check_ins', fn (Blueprint $table) => $table->dropIndex('goal_check_ins_goal_id_foreign'));
        }
    }

    public function down(): void
    {
        Schema::table('goal_check_ins', function (Blueprint $table) {
            // MySQL let the unique index serve the goal_id foreign key; restore the original
            // foreign-key index before dropping it.
            if (! Schema::hasIndex('goal_check_ins', 'goal_check_ins_goal_id_foreign')) {
                $table->index('goal_id', 'goal_check_ins_goal_id_foreign');
            }
            $table->dropUnique('goal_check_ins_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
