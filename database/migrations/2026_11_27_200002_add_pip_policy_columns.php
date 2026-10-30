<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity HP-8f (PIP → PIP policy): extensions counted against the policy's limit, the approval of a plan
 * and of its outcome (evaluation), and checkpoints added from the policy's start and close checklists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('improvement_plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('extension_count')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->string('requested_outcome', 16)->nullable()->comment('completed|unsuccessful while the outcome waits for approval');
            $table->string('evaluation_from', 16)->nullable()->comment('active|extended: where a sent-back evaluation returns');
        });
        Schema::table('improvement_plan_checkpoints', function (Blueprint $table) {
            $table->string('kind', 16)->default('checkpoint')->comment('checkpoint|start|close');
            $table->string('owner', 16)->nullable()->comment('manager|hr|employee (checklist items)');
        });
    }

    public function down(): void
    {
        Schema::table('improvement_plan_checkpoints', fn (Blueprint $table) => $table->dropColumn(['kind', 'owner']));
        Schema::table('improvement_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['extension_count', 'approved_at', 'decision_note', 'requested_outcome', 'evaluation_from']);
        });
    }
};
