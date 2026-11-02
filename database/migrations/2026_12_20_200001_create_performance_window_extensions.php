<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity GO-2 (matrix 27 §27.2.2 #6–9, §27.3.3 #3–5, §27.4.1 #6): cycle population, windows and withdrawal.
 *
 * - performance_window_extensions: more time for one stage of a running cycle (goal setting, self / manager / approver /
 *   peer review) or for a 360 feedback round, for everyone or for one employee, with the new end date and a reason.
 *   Append-only: a later extension adds a row (the latest end date wins); nothing is changed or deleted.
 * - appraisals.withdrawn_at / withdrawn_by / withdrawal_reason: "Withdraw from cycle" (owner decision PER-10 / HP-8k:
 *   nothing is deleted) — a non-finalized appraisal leaves the cycle with a reason; its history stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_window_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_cycle_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('feedback_round_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('stage', 32)->comment('goal_setting|self_review|manager_review|approver_review|peer_review|feedback_360');
            $table->foreignId('employee_id')->nullable()->constrained()->cascadeOnDelete()->comment('null = everyone in the cycle or round');
            $table->date('previous_ends_on')->nullable()->comment('the end date that applied before this extension');
            $table->date('ends_on');
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'performance_cycle_id', 'stage'], 'perf_window_ext_cycle_stage_idx');
            $table->index(['tenant_id', 'feedback_round_id', 'employee_id'], 'perf_window_ext_round_emp_idx');
        });

        Schema::table('appraisals', function (Blueprint $table) {
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users', 'id', 'appraisals_withdrawn_by_fk')->nullOnDelete();
            $table->string('withdrawal_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('appraisals', function (Blueprint $table) {
            $table->dropForeign('appraisals_withdrawn_by_fk');
            $table->dropColumn(['withdrawn_at', 'withdrawn_by', 'withdrawal_reason']);
        });
        Schema::dropIfExists('performance_window_extensions');
    }
};
