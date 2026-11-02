<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity WO-11 (matrix 23 §23.6.1–§23.6.4, §23.2.2, §23.11): the separation desk.
 *
 * - exit_cases: the approver's separation reason and remark, kept apart from the reason the employee (or HR at initiation)
 *   gave (`workforce_reason_id`); notice relaxation days (waive part of the notice shortfall; 0 = none, as before); the
 *   comment that goes with a rehire decision; the month the full and final settlement is to be paid in (blank = the month
 *   of the last working day, as before).
 * - exit_clearances: a due date per stage (the last working day less the stage offset of the checklist or the exit
 *   policy; blank = none, as before), the owner's expected completion date, why a stage was closed without being cleared
 *   (`withdrawn` — the exit was withdrawn; `cancelled` — cancelled with a reason, D-WO3), and one evidence file.
 * - exit_withdrawal_requests: a request to withdraw a resignation that waits for approval when the organisation asks for it
 *   (exit.withdrawal.requires_approval; D-WO8). `open_key` ("case:<id>") is set while the request is pending and unique per
 *   tenant: one open request per exit, even under a race. Requests are never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exit_cases', function (Blueprint $table) {
            $table->foreignId('approver_reason_id')->nullable()->after('workforce_reason_id')
                ->constrained('workforce_reasons', 'id', 'exit_cases_approver_reason_fk')->nullOnDelete();
            $table->text('approver_remark')->nullable()->after('approver_reason_id');
            $table->unsignedSmallInteger('notice_relaxation_days')->default(0)->after('notice_end_date');
            $table->text('rehire_comment')->nullable()->after('rehire_block_reason_id');
            $table->date('settlement_month')->nullable()->after('last_working_day');
        });

        Schema::table('exit_clearances', function (Blueprint $table) {
            $table->date('due_on')->nullable()->after('status');
            $table->date('expected_on')->nullable()->after('due_on');
            $table->string('closed_reason', 16)->nullable()->after('remarks');
            $table->string('evidence_path')->nullable()->after('cleared_at');
            $table->string('evidence_name')->nullable()->after('evidence_path');
            $table->foreignId('evidence_by')->nullable()->after('evidence_name')
                ->constrained('users', 'id', 'exit_clearances_evidence_by_fk')->nullOnDelete();
            $table->timestamp('evidence_at')->nullable()->after('evidence_by');

            $table->index(['tenant_id', 'status', 'due_on'], 'exit_clearances_status_due_index');
        });

        Schema::create('exit_withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exit_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users', 'id', 'exit_withdrawals_requested_by_fk')->nullOnDelete();
            $table->string('reason', 500);
            $table->string('status', 16)->default('pending');
            $table->string('open_key', 64)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users', 'id', 'exit_withdrawals_decided_by_fk')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'open_key'], 'exit_withdrawals_open_unique');
            $table->index(['tenant_id', 'status'], 'exit_withdrawals_status_index');
            $table->index(['tenant_id', 'employee_id', 'status'], 'exit_withdrawals_employee_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exit_withdrawal_requests');

        Schema::table('exit_clearances', function (Blueprint $table) {
            $table->dropIndex('exit_clearances_status_due_index');
            $table->dropForeign('exit_clearances_evidence_by_fk');
            $table->dropColumn(['due_on', 'expected_on', 'closed_reason', 'evidence_path', 'evidence_name', 'evidence_by', 'evidence_at']);
        });

        Schema::table('exit_cases', function (Blueprint $table) {
            $table->dropForeign('exit_cases_approver_reason_fk');
            $table->dropColumn(['approver_reason_id', 'approver_remark', 'notice_relaxation_days', 'rehire_comment', 'settlement_month']);
        });
    }
};
