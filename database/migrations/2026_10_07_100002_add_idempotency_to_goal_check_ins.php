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
    }

    public function down(): void
    {
        Schema::table('goal_check_ins', function (Blueprint $table) {
            $table->dropUnique('goal_check_ins_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
